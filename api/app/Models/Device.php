<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'unique_device_code',
        'serial_number',
        'category_id',
        'branch_id',
        'inventory_batch_id',
        'stock_transfer_request_id',
        'brand_id',
        'product_model_id',
        'owner_id',
        'iot_module_id',
        'brand',
        'model',
        'description',
        'color',
        'purchase_date',
        'purchase_price',
        'purchase_location',
        'status',
        'inventory_status',
        'sale_id',
        'sold_at',
        'sold_by_id',
        'notes',
        'photos',
        'last_location_id',
        'last_seen_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'inventory_status' => InventoryStatus::class,
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
            'photos' => 'array',
            'last_seen_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    /**
     * Get the owner of the device.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the category of the device.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DeviceCategory::class, 'category_id');
    }

    /**
     * Get the brand of the device from product catalog.
     */
    public function brandRelation(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Get the product model of the device from product catalog.
     */
    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    /**
     * Get the IoT module attached to the device.
     */
    public function iotModule(): BelongsTo
    {
        return $this->belongsTo(IotModule::class, 'iot_module_id');
    }

    /**
     * Get the locations for the device.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(DeviceLocation::class);
    }

    /**
     * Get the geofences for the device.
     */
    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    /**
     * Get the last known location of the device.
     */
    public function lastLocation(): BelongsTo
    {
        return $this->belongsTo(DeviceLocation::class, 'last_location_id');
    }

    /**
     * Get the sale this device was sold in.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function stockTransferRequest(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class);
    }

    /**
     * Get the staff member who sold this device.
     */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_id');
    }

    /**
     * Get the branch this device belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class);
    }

    /**
     * Get the ownership history for the device.
     */
    public function ownershipHistory(): HasMany
    {
        return $this->hasMany(DeviceOwnershipHistory::class);
    }

    /**
     * Get the warranties for the device.
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class);
    }

    /**
     * Get the theft reports for the device.
     */
    public function theftReports(): HasMany
    {
        return $this->hasMany(TheftReport::class);
    }

    /**
     * Scope a query to only include active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('status', DeviceStatus::ACTIVE);
    }

    /**
     * Scope a query to only include stolen devices.
     */
    public function scopeStolen($query)
    {
        return $query->where('status', DeviceStatus::STOLEN);
    }

    /**
     * Scope a query to filter by owner.
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('owner_id', $userId);
    }

    /**
     * Check if the device is stolen.
     */
    public function isStolen(): bool
    {
        return $this->status === DeviceStatus::STOLEN;
    }

    /**
     * Check if the device has an IoT module attached.
     */
    public function hasIotModule(): bool
    {
        return $this->iot_module_id !== null;
    }

    /**
     * Scope a query to only include devices in stock.
     */
    public function scopeInStock($query)
    {
        return $query->where('inventory_status', InventoryStatus::IN_STOCK);
    }

    /**
     * Scope a query to only include sold devices.
     */
    public function scopeSold($query)
    {
        return $query->where('inventory_status', InventoryStatus::SOLD);
    }

    /**
     * Scope a query to only include available devices (in stock or returned).
     */
    public function scopeAvailable($query)
    {
        return $query->whereIn('inventory_status', [
            InventoryStatus::IN_STOCK,
            InventoryStatus::RETURNED,
        ]);
    }

    /**
     * Scope a query to filter by branch.
     */
    public function scopeInBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope a query to filter for a specific branch.
     */
    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope a query to filter by current user's branch.
     */
    public function scopeForCurrentUserBranch($query)
    {
        $user = auth()->user();
        if ($user?->branch_id && $user->hasRole('customer_service')) {
            return $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * Check if the device is in stock.
     */
    public function isInStock(): bool
    {
        return $this->inventory_status === InventoryStatus::IN_STOCK;
    }

    /**
     * Check if the device is sold.
     */
    public function isSold(): bool
    {
        return $this->inventory_status === InventoryStatus::SOLD;
    }
}
