<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

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
     * Get the total revenue for the shift.
     */
    public function getRevenueAttribute()
    {
        return $this->sales()
            ->where('status', 'completed')
            ->sum('total_amount');
    }

    /**
     * Get the total number of sales for the shift.
     */
    public function getSalesCountAttribute()
    {
        return $this->sales()->count();
    }

    /**
     * Get the total amount of cash sales for the shift.
     */
    public function getCashSalesAttribute()
    {
        return $this->sales()
            ->where('status', 'completed')
            ->where('payment_method', 'cash')
            ->sum('total_amount');
    }

    /**
     * Get the duration of the shift in minutes.
     */
    public function getDurationAttribute()
    {
        if (! $this->ended_at) {
            return null;
        }

        return $this->ended_at->diffInMinutes($this->started_at);
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
        $this->update(array_merge([
            'status' => 'completed',
            'ended_at' => now(),
        ], $data));
    }

    /**
     * Cancel the shift.
     */
    public function cancel(): void
    {
        $this->update([
            'status' => 'cancelled',
            'ended_at' => now(),
        ]);
    }
}
