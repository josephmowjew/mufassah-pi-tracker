<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'device_id',
        'subscription_plan_id',
        'item_description',
        'unit_price',
        'discount',
        'tax',
        'total_price',
        'cost_price',
        'selling_price',
        'profit_amount',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'profit_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the sale this item belongs to.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Get the device being sold.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the subscription plan (if bundled).
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * Calculate the total price.
     */
    public function calculateTotal(): void
    {
        $this->total_price = ($this->unit_price - $this->discount) + $this->tax;
    }
}
