<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'branch_id',
        'supplier',
        'reference_number',
        'purchase_date',
        'purchase_location',
        'status',
        'notes',
        'received_by_id',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public static function generateReceiptNumber(): string
    {
        $year = now()->format('Y');

        // Trashed rows still occupy their unique receipt_number, so the
        // sequence must count them or the next insert collides.
        $lastReceipt = static::withTrashed()
            ->whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        $sequence = $lastReceipt ? ((int) substr($lastReceipt->receipt_number, -5)) + 1 : 1;

        return sprintf('REC-%s-%05d', $year, $sequence);
    }

    /**
     * Soft deletes do not cascade at the database level, so a deleted receipt
     * has to hide its batches explicitly.
     */
    protected static function booted(): void
    {
        static::deleting(function (InventoryReceipt $receipt) {
            if ($receipt->isForceDeleting()) {
                return;
            }

            $receipt->batches()->each(fn (InventoryBatch $batch) => $batch->delete());
        });

        static::restoring(function (InventoryReceipt $receipt) {
            $receipt->batches()->withTrashed()->each(fn (InventoryBatch $batch) => $batch->restore());
        });
    }

    public function summary(): array
    {
        $batches = $this->relationLoaded('batches')
            ? $this->batches
            : $this->batches()->with('devices.sale.items')->get();

        $units = (int) $batches->sum('quantity_received');
        $totalCost = round($batches->sum(fn (InventoryBatch $batch) => (float) $batch->buying_price * $batch->quantity_received), 2);
        $expectedRevenue = round($batches->sum(fn (InventoryBatch $batch) => (float) $batch->target_selling_price * $batch->quantity_received), 2);
        $expectedProfit = round($expectedRevenue - $totalCost, 2);
        $realizedRevenue = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['realized_revenue']), 2);
        $realizedProfit = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['realized_gross_profit']), 2);
        $posRevenue = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['pos_revenue']), 2);
        $posProfit = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['pos_gross_profit']), 2);
        $administrativeSaleValue = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['administrative_sale_value']), 2);
        $administrativeSaleProfit = round($batches->sum(fn (InventoryBatch $batch) => $batch->summary()['administrative_sale_profit']), 2);

        return [
            'lines' => $batches->count(),
            'units' => $units,
            'total_cost' => $totalCost,
            'expected_revenue' => $expectedRevenue,
            'expected_gross_profit' => $expectedProfit,
            'realized_revenue' => $realizedRevenue,
            'realized_gross_profit' => $realizedProfit,
            'pos_revenue' => $posRevenue,
            'pos_gross_profit' => $posProfit,
            'administrative_sale_value' => $administrativeSaleValue,
            'administrative_sale_profit' => $administrativeSaleProfit,
            'combined_inventory_realized_value' => $realizedRevenue,
            'combined_gross_profit_loss' => $realizedProfit,
            'margin' => $realizedRevenue > 0 ? round(($realizedProfit / $realizedRevenue) * 100, 2) : null,
        ];
    }
}
