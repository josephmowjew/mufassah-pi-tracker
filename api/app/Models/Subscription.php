<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'cancellation_reason',
        'auto_renew',
        'last_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function lastPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'last_payment_id');
    }

    public function isActive(): bool
    {
        // `ends_at` is nullable, so this must not assume it is a date: calling
        // isFuture() on null is a fatal error, and every serialisation of a
        // subscription goes through here (SubscriptionResource).
        //
        // An open-ended subscription (no end date) is deliberately NOT active,
        // which matches scopeActive() — a SQL `ends_at >= now()` comparison
        // cannot match NULL either. The two must agree, or the same row would be
        // "active" in one place and not the other.
        return $this->status === SubscriptionStatus::Active
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    public function scopeActive($query)
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '>=', now());
    }
}
