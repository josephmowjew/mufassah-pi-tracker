<?php

namespace App\Models;

use App\Support\LocalTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    public const MAX_HOURS = 10;

    public static function closingTime(CarbonInterface $day): Carbon
    {
        return LocalTime::instantUtc($day)->setTimezone(config('app.local_timezone'))->setTime(18, 0);
    }

    public function automaticEndAt(): Carbon
    {
        $closing = self::closingTime($this->started_at);
        if ($this->started_at->greaterThanOrEqualTo($closing)) {
            // Legacy shifts could start after closing time. Use the next closing.
            $closing->addDay();
        }

        return $this->started_at->copy()->addHours(self::MAX_HOURS)->min($closing)->utc();
    }

    public function isDueToEnd(CarbonInterface $at): bool
    {
        return $this->automaticEndAt()->lessThanOrEqualTo($at);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'started_at',
        'ended_at',
        'status',
        'notes',
        'opening_cash',
        'closing_cash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'closing_cash' => 'decimal:2',
        ];
    }

    /**
     * Get the user that owns the shift.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the branch for the shift.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the sales for the shift.
     */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Get every break taken during the shift, oldest first.
     *
     * Breaks are a child record rather than columns on the shift: an employee
     * may take several in one day, and the parent `status` must stay `active`
     * while they are away.
     */
    public function breaks()
    {
        return $this->hasMany(ShiftBreak::class)->orderBy('started_at');
    }

    /**
     * Get the break that is currently running, if any.
     */
    public function openBreak()
    {
        return $this->hasOne(ShiftBreak::class)->whereNull('ended_at');
    }

    /**
     * Get the total revenue for the shift.
     *
     * When the sales relation is eager-loaded (list endpoints), aggregates are
     * computed from the collection instead of issuing a per-row query.
     */
    public function getRevenueAttribute()
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales
                ->where('status', 'completed')
                ->sum('total_amount');
        }

        return $this->sales()
            ->where('status', 'completed')
            ->sum('total_amount');
    }

    /**
     * Get the total number of sales for the shift.
     *
     * When the sales relation is eager-loaded (list endpoints), the count is
     * taken from the collection instead of issuing a per-row query.
     */
    public function getSalesCountAttribute()
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales->count();
        }

        return $this->sales()->count();
    }

    /**
     * Get the total amount of cash sales for the shift.
     *
     * When the sales relation is eager-loaded (list endpoints), aggregates are
     * computed from the collection instead of issuing a per-row query.
     */
    public function getCashSalesAttribute()
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales
                ->where('status', 'completed')
                ->where('payment_method', 'cash')
                ->sum('total_amount');
        }

        return $this->sales()
            ->where('status', 'completed')
            ->where('payment_method', 'cash')
            ->sum('total_amount');
    }

    /**
     * Get the duration of the shift in minutes.
     *
     * Carbon 3 returns a signed difference, so the arguments must run
     * start -> end. Calling it the other way round made every completed
     * shift report a negative duration.
     */
    public function getDurationAttribute()
    {
        if (! $this->ended_at) {
            return null;
        }

        return $this->started_at->diffInMinutes($this->ended_at);
    }

    /**
     * Get the total break time in minutes.
     *
     * A running break is counted up to now, so the live "worked so far" figure
     * on the dashboard does not understate time spent away from the counter.
     * A finished shift never has a running break — {@see self::complete()}
     * closes any that is still open.
     */
    public function getBreakMinutesAttribute(): float
    {
        $shiftEnd = $this->ended_at ?? now();

        return round((float) $this->breaksForAggregate()->sum(function (ShiftBreak $break) use ($shiftEnd) {
            $breakEnd = $break->ended_at?->min($shiftEnd) ?? $shiftEnd;

            return max(0, round($break->started_at->diffInMinutes($breakEnd), 2));
        }), 2);
    }

    /**
     * Get how many breaks were taken during the shift.
     */
    public function getBreakCountAttribute(): int
    {
        return $this->breaksForAggregate()->count();
    }

    /**
     * Get the time actually spent working, in minutes.
     *
     * Gross time minus break time. For an active shift the gross side is
     * measured against the clock, since `ended_at` is still null.
     */
    public function getWorkedMinutesAttribute(): ?float
    {
        $gross = $this->ended_at !== null
            ? (float) $this->duration
            : max(0, (float) $this->started_at->diffInMinutes(now()));

        return round(max(0, $gross - $this->break_minutes), 2);
    }

    /**
     * Whether the employee is currently on a break.
     *
     * This is derived from an open break row, never from `status`. Setting a
     * status of `on_break` would break `User::activeShift()`, which is scoped
     * to `status = 'active'` — `current` would report no shift, `end` would
     * 404, and `start` would allow a second concurrent shift.
     */
    public function getOnBreakAttribute(): bool
    {
        if ($this->relationLoaded('breaks')) {
            return $this->breaks->contains(fn (ShiftBreak $break) => $break->ended_at === null);
        }

        return $this->breaks()->whereNull('ended_at')->exists();
    }

    /**
     * Scope to filter active shifts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter completed shifts.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to filter shifts by user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter shifts by branch.
     */
    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Check if the shift is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the shift is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Mark the shift as completed.
     */
    public function complete(array $data = []): void
    {
        $endedAt = $data['ended_at'] ?? now();

        // Closed before the status flips, so the invariant "a completed shift
        // has no open break" holds for every caller, not just the controller.
        // Without this, ending a shift mid-break leaves a break with no end,
        // which every report would read as an unbounded break.
        $this->closeOpenBreaks($endedAt);

        $this->update(array_merge([
            'status' => 'completed',
            'ended_at' => $endedAt,
        ], $data));
    }

    /**
     * Cancel the shift.
     */
    public function cancel(?CarbonInterface $endedAt = null): void
    {
        $endedAt ??= now();

        // Same reasoning as {@see self::complete()}: a cancelled shift must not
        // leave a break running forever.
        $this->closeOpenBreaks($endedAt);

        $this->update([
            'status' => 'cancelled',
            'ended_at' => $endedAt,
        ]);
    }

    /**
     * Close any break that is still running.
     *
     * Returns the number of rows closed, which lets a caller tell "there was an
     * open break" from "there was not" without a second query.
     */
    public function closeOpenBreaks(?CarbonInterface $at = null): int
    {
        $at ??= now();
        $openBreaks = $this->breaks()->whereNull('ended_at')->get();
        foreach ($openBreaks as $break) {
            // Older records can have a break that began after the shift's cutoff.
            $break->update(['ended_at' => $at->max($break->started_at)]);
        }

        if ($openBreaks->isNotEmpty()) {
            $this->unsetRelation('breaks');
        }

        return $openBreaks->count();
    }

    /**
     * @return Collection<int, ShiftBreak>
     */
    private function breaksForAggregate(): Collection
    {
        // Loaded rather than queried directly so the four break-related
        // accessors share one fetch: a resource that reads break_count,
        // break_minutes and worked_minutes would otherwise issue three
        // identical queries per shift row.
        if (! $this->relationLoaded('breaks')) {
            $this->load('breaks');
        }

        return $this->breaks;
    }
}
