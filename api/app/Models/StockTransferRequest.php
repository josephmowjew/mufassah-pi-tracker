<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferRequest extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['request_number', 'from_branch_id', 'to_branch_id', 'requested_by_id', 'decided_by_id', 'status', 'is_override', 'overridden_by_id', 'reason', 'notes', 'decision_reason', 'decided_at', 'overridden_at'];

    protected function casts(): array
    {
        return ['is_override' => 'boolean', 'decided_at' => 'datetime', 'overridden_at' => 'datetime'];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferRequestItem::class);
    }

    public static function nextNumber(): string
    {
        return sprintf('STR-%s-%06d', now()->format('Y'), (static::whereYear('created_at', now()->year)->max('id') ?? 0) + 1);
    }
}
