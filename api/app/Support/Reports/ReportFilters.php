<?php

namespace App\Support\Reports;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The single source of truth for how a report is scoped.
 *
 * Every report endpoint and the exporter resolve their filters through here, so
 * a figure shown on screen and the same figure exported to Excel can never be
 * computed from different date or branch semantics.
 *
 * Scope resolution by role:
 *  - `super_admin` may report globally (all branches) or pin one branch.
 *  - Any other admin role is *always* pinned to their own branch; a `branch_id`
 *    or `all_branches` they send is ignored rather than rejected, so a widened
 *    scope can never be granted by tampering with the query string.
 */
final readonly class ReportFilters
{
    public const GROUP_BY_DAY = 'day';

    public const GROUP_BY_WEEK = 'week';

    public const GROUP_BY_MONTH = 'month';

    public const GROUP_BY_YEAR = 'year';

    public function __construct(
        public ?Carbon $from,
        public ?Carbon $to,
        public ?int $branchId,
        public bool $allBranches,
        public string $groupBy = self::GROUP_BY_MONTH,
    ) {}

    /**
     * @param  array<string, mixed>  $extra  Additional rules merged into the payload.
     */
    public static function fromRequest(Request $request, array $extra = []): self
    {
        $validated = $request->validate(array_merge(self::rules(), $extra));

        $user = $request->user();
        $requestedBranchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $wantsGlobal = (bool) ($validated['all_branches'] ?? false);

        $branchId = self::resolveBranchScope($user, $requestedBranchId, $wantsGlobal);

        return new self(
            from: isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null,
            to: isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null,
            branchId: $branchId,
            allBranches: $branchId === null,
            groupBy: $validated['group_by'] ?? self::GROUP_BY_MONTH,
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
            ],
            'all_branches' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function groupByRules(): array
    {
        return [
            'group_by' => ['nullable', 'string', 'in:'.implode(',', [
                self::GROUP_BY_DAY,
                self::GROUP_BY_WEEK,
                self::GROUP_BY_MONTH,
                self::GROUP_BY_YEAR,
            ])],
        ];
    }

    private static function resolveBranchScope(?User $user, ?int $requestedBranchId, bool $wantsGlobal): ?int
    {
        if ($user?->hasRole('super_admin')) {
            if ($wantsGlobal) {
                return null;
            }

            return $requestedBranchId;
        }

        return $user?->requiredBranchScopeId();
    }

    /**
     * True when the report spans every branch.
     */
    public function isGlobal(): bool
    {
        return $this->branchId === null;
    }

    public function hasDateRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function fromDate(): ?string
    {
        return $this->from?->toDateString();
    }

    public function toDate(): ?string
    {
        return $this->to?->toDateString();
    }

    /**
     * The date format used to bucket a time series for the chosen `group_by`.
     */
    public function dateFormat(): string
    {
        return match ($this->groupBy) {
            self::GROUP_BY_DAY => 'Y-m-d',
            self::GROUP_BY_WEEK => 'Y-\WW',
            self::GROUP_BY_YEAR => 'Y',
            default => 'Y-m',
        };
    }

    public function periodLabel(): string
    {
        if (! $this->hasDateRange()) {
            return 'All time';
        }

        if ($this->from !== null && $this->to !== null) {
            return $this->fromDate().' to '.$this->toDate();
        }

        return $this->from !== null
            ? 'From '.$this->fromDate()
            : 'Up to '.$this->toDate();
    }

    /**
     * Human-readable scope for the summary sheet. Falls back to the id when the
     * caller has not supplied a name, so a label is never silently wrong.
     */
    public function scopeLabel(?string $branchName = null): string
    {
        if ($this->isGlobal()) {
            return 'All branches';
        }

        return $branchName ?? "Branch #{$this->branchId}";
    }

    /**
     * A short, filename-safe description of the scope and period.
     */
    public function fileLabel(?string $branchName = null): string
    {
        $parts = [$this->isGlobal() ? 'all-branches' : self::slug($branchName ?? "branch-{$this->branchId}")];

        if ($this->from !== null || $this->to !== null) {
            $parts[] = $this->fromDate() ?? 'start';
            $parts[] = $this->toDate() ?? 'now';
        } else {
            $parts[] = 'all-time';
        }

        return implode('_', array_filter($parts));
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-') ?: 'branch';
    }
}
