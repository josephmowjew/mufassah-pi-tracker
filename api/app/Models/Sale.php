<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sale_number',
        'customer_id',
        'branch_id',
        'sold_by_id',
        'shift_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'payment_reference',
        'payment_amount',
        'change_due',
        'status',
        'notes',
        'refund_reason',
        'sale_date',
        'vat_applicable',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'change_due' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'status' => SaleStatus::class,
            'sale_date' => 'datetime',
            'vat_applicable' => 'boolean',
        ];
    }

    /**
     * Get the customer who made the purchase.
     */
    public function layby(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Layby::class);
    }

    public function tenderLabel(): string
    {
        return $this->payment_method?->label() ?? 'Layby';
    }

    public function tenderBreakdown(): array
    {
        return $this->payment_method === null ? ($this->layby?->tenderBreakdown() ?? []) : [];
    }

    public function scopeCompletedForReporting($query)
    {
        return $query->where(fn ($sales) => $sales->where('status', SaleStatus::COMPLETED)
            ->orWhere(fn ($returned) => $returned->where('status', SaleStatus::REFUNDED)->whereNull('payment_method')));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the staff member who made the sale.
     */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_id');
    }

    /**
     * Get the branch where this sale was made.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the shift for this sale.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the items in this sale.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Get the devices sold in this sale.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Generate a unique sale number.
     */
    public static function generateSaleNumber(): string
    {
        $year = now()->format('Y');
        $lastSale = static::whereYear('created_at', $year)
            ->where('sale_number', 'like', 'SALE-%')
            ->orderByDesc('id')
            ->first();

        $sequence = $lastSale ? ((int) substr($lastSale->sale_number, -5)) + 1 : 1;

        return sprintf('SALE-%s-%05d', $year, $sequence);
    }

    /**
     * Check if the sale is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === SaleStatus::COMPLETED;
    }

    /**
     * Check if the sale is paid.
     */
    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Completed;
    }

    /**
     * Calculate totals from items.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = round((float) $this->items->sum('unit_price'), 2);
        $this->tax_amount = round((float) $this->items->sum('tax'), 2);
        $this->discount_amount = round((float) $this->items->sum('discount'), 2);
        $this->total_amount = round((float) $this->items->sum('total_price'), 2);
    }

    /**
     * Scope a query to filter by branch.
     */
    public function scopeInBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope a query to filter by current user's branch.
     */
    public function scopeForCurrentUserBranch($query)
    {
        $user = Auth::user();
        if ($user instanceof User && ! $user->hasRole('super_admin')) {
            return $query->where('branch_id', $user->requiredBranchScopeId());
        }

        return $query;
    }
}
