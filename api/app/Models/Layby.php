<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Layby extends Model
{
    public const TERMS = 'The store holds the listed device until full payment and verified pickup. '
        .'Pay the remaining balance by the stated due date in flexible instalments. '
        .'Contact the branch to agree to an extension or request cancellation before pickup. '
        .'On approved cancellation the store refunds verified payments to their original tender. '
        .'There is no interest or cancellation fee. Overdue agreements are reviewed by the branch.';

    protected $table = 'laybys';

    protected $guarded = ['id'];

    protected $casts = [
        'original_due_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'handed_over_at' => 'immutable_datetime',
        'returned_at' => 'immutable_datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LaybyEntry::class)->orderBy('id');
    }

    public function netReceived(): int
    {
        return (int) $this->entries->sum(fn (LaybyEntry $entry) => match ($entry->kind) {
            'collection' => $entry->amount_minor,
            'correction', 'refund' => -$entry->amount_minor,
            default => 0,
        });
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active'
            && $this->due_date->toDateString() < now(config('app.local_timezone'))->toDateString();
    }

    public function tenderBreakdown(): array
    {
        $handover = $this->entries->firstWhere('kind', 'handover');
        if (isset($handover?->details['tenders'])) {
            return $handover->details['tenders'];
        }

        return $this->entries->whereIn('kind', ['collection', 'correction'])
            ->groupBy('tender')
            ->map(fn ($entries) => (int) $entries->sum(
                fn (LaybyEntry $entry) => $entry->kind === 'collection' ? $entry->amount_minor : -$entry->amount_minor
            ))
            ->filter(fn (int $amount) => $amount > 0)->all();
    }
}
