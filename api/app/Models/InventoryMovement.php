<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_RESERVATION_CANCELLED = 'reservation_cancelled';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALE_CANCELLED = 'sale_cancelled';

    public const TYPE_REFUND = 'refund';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

    public const TYPE_FAULTY = 'faulty';

    public const TYPE_RETURNED = 'returned';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_ADMINISTRATIVE_SALE = 'administrative_sale';

    protected $fillable = [
        'movement_number',
        'movement_type',
        'device_id',
        'inventory_batch_id',
        'inventory_receipt_id',
        'sale_id',
        'sale_item_id',
        'category_id',
        'branch_id',
        'from_branch_id',
        'to_branch_id',
        'brand',
        'model',
        'color',
        'quantity',
        'unit_cost',
        'unit_price',
        'total_cost',
        'total_amount',
        'profit_amount',
        'previous_status',
        'new_status',
        'previous_owner_id',
        'new_owner_id',
        'reference_type',
        'reference_id',
        'reason',
        'notes',
        'created_by_id',
        'is_backfilled',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'is_backfilled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DeviceCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public static function generateMovementNumber(): string
    {
        $year = now()->format('Y');
        $lastMovement = static::whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        $sequence = $lastMovement ? ((int) substr($lastMovement->movement_number, -6)) + 1 : 1;

        return sprintf('MOVE-%s-%06d', $year, $sequence);
    }

    public static function types(): array
    {
        return [
            self::TYPE_RECEIPT,
            self::TYPE_RESERVATION,
            self::TYPE_RESERVATION_CANCELLED,
            self::TYPE_SALE,
            self::TYPE_SALE_CANCELLED,
            self::TYPE_REFUND,
            self::TYPE_TRANSFER,
            self::TYPE_ADJUSTMENT_IN,
            self::TYPE_ADJUSTMENT_OUT,
            self::TYPE_FAULTY,
            self::TYPE_RETURNED,
            self::TYPE_WRITE_OFF,
            self::TYPE_ADMINISTRATIVE_SALE,
        ];
    }
}
