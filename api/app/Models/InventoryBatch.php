<?php

namespace App\Models;

use App\Enums\InventoryStatus;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'batch_number',
        'inventory_receipt_id',
        'branch_id',
        'category_id',
        'brand',
        'model',
        'color',
        'quantity_received',
        'buying_price',
        'target_selling_price',
        'purchase_date',
        'purchase_location',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'buying_price' => 'decimal:2',
            'target_selling_price' => 'decimal:2',
            'purchase_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DeviceCategory::class, 'category_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public static function generateBatchNumber(): string
    {
        $year = now()->format('Y');

        // Trashed rows still occupy their unique batch_number, so the sequence
        // must count them. Without withTrashed() a soft-deleted batch would
        // hand its number to the next insert and violate the unique index.
        $lastBatch = static::withTrashed()
            ->whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        $sequence = $lastBatch ? ((int) substr($lastBatch->batch_number, -5)) + 1 : 1;

        return sprintf('BATCH-%s-%05d', $year, $sequence);
    }

    public function summary(): array
    {
        $devices = $this->relationLoaded('devices') ? $this->devices : $this->devices()->get();
        $devices->loadMissing('sale.items');

        $inStock = $devices->where('inventory_status', InventoryStatus::IN_STOCK)->count();
        $reserved = $devices->where('inventory_status', InventoryStatus::RESERVED)->count();
        $sold = $devices->where('inventory_status', InventoryStatus::SOLD)->count();
        $returned = $devices->where('inventory_status', InventoryStatus::RETURNED)->count();
        $faulty = $devices->where('inventory_status', InventoryStatus::FAULTY)->count();

        $deviceIds = $devices->pluck('id')->all();

        $completedSaleItems = $devices
            ->flatMap(fn (Device $device) => $device->sale?->status === SaleStatus::COMPLETED
                ? $device->sale->items
                : collect())
            ->whereIn('device_id', $deviceIds)
            ->unique('id');

        $realizedRevenue = round((float) $completedSaleItems->sum('total_price'), 2);
        $posRevenue = $realizedRevenue;
        $posProfit = round((float) $completedSaleItems->sum('profit_amount'), 2);
        $administrativeSales = InventoryMovement::query()
            ->where('inventory_batch_id', $this->id)
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->get(['total_amount', 'profit_amount']);
        $administrativeSaleValue = round((float) $administrativeSales->sum('total_amount'), 2);
        $administrativeSaleProfit = round((float) $administrativeSales->sum('profit_amount'), 2);
        $realizedRevenue = round($posRevenue + $administrativeSaleValue, 2);
        $realizedProfit = round($posProfit + $administrativeSaleProfit, 2);
        $totalCost = round((float) $this->buying_price * $this->quantity_received, 2);
        $expectedProfit = round(((float) $this->target_selling_price - (float) $this->buying_price) * $this->quantity_received, 2);
        $margin = $realizedRevenue > 0 ? round(($realizedProfit / $realizedRevenue) * 100, 2) : null;

        return [
            'in_stock' => $inStock,
            'reserved' => $reserved,
            'sold' => $sold,
            'returned' => $returned,
            'faulty' => $faulty,
            'total_cost' => $totalCost,
            'realized_revenue' => $realizedRevenue,
            'realized_gross_profit' => $realizedProfit,
            'pos_revenue' => $posRevenue,
            'pos_gross_profit' => $posProfit,
            'administrative_sale_value' => $administrativeSaleValue,
            'administrative_sale_profit' => $administrativeSaleProfit,
            'combined_inventory_realized_value' => $realizedRevenue,
            'combined_gross_profit_loss' => $realizedProfit,
            'expected_gross_profit' => $expectedProfit,
            'margin' => $margin,
        ];
    }
}
