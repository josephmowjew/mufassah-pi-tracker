<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Models\ActivityLog;
use App\Models\InventoryBatch;
use App\Models\ProductModel;
use App\Models\User;

class InventoryBatchPricingService
{
    /** Apply a non-null catalog price while the caller holds the model lock in a transaction. */
    public function applyCatalogPrice(ProductModel $productModel, User $user): void
    {
        $brand = $productModel->brand()->first();

        $batches = InventoryBatch::query()
            ->where(function ($query) use ($productModel, $brand) {
                // Batches written since the catalog link exists match on id,
                // which survives a rename. Older rows only carry the text
                // identity and are matched as a fallback.
                $query->where('product_model_id', $productModel->id)
                    ->orWhere(function ($legacy) use ($productModel, $brand) {
                        $legacy->whereNull('product_model_id')
                            ->where('category_id', $brand?->category_id)
                            ->where('brand', $brand?->name)
                            ->where('model', $productModel->name);
                    });
            })
            ->where('target_selling_price', '<>', $productModel->base_price)
            ->whereHas('devices', fn ($q) => $q->where('inventory_status', InventoryStatus::IN_STOCK))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            $oldBatchPrice = $batch->target_selling_price;
            $affected = $batch->devices()->where('inventory_status', InventoryStatus::IN_STOCK)->count();
            if ($affected === 0) {
                continue;
            }
            $batch->update(['target_selling_price' => $productModel->base_price]);
            ActivityLog::create([
                'log_name' => 'price_management',
                'description' => 'Catalog price cascaded to batch',
                'subject_type' => InventoryBatch::class,
                'subject_id' => $batch->id,
                'causer_type' => $user::class,
                'causer_id' => $user->id,
                'event' => 'catalog_price_cascade',
                'properties' => [
                    'old_price' => $oldBatchPrice,
                    'new_price' => $productModel->base_price,
                    'affected_in_stock_devices' => $affected,
                ],
            ]);
        }
    }
}
