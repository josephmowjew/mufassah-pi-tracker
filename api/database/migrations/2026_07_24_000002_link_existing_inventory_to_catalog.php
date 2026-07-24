<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->select(['id', 'category_id', 'brand', 'model', 'brand_id', 'product_model_id'])
            ->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    $brand = DB::table('brands')
                        ->where('category_id', $device->category_id)
                        ->where('name', $device->brand)
                        ->first(['id']);

                    if (! $brand) {
                        continue;
                    }

                    $model = DB::table('product_models')
                        ->where('brand_id', $brand->id)
                        ->where('name', $device->model)
                        ->first(['id']);

                    $updates = [];
                    if ((int) $device->brand_id !== (int) $brand->id) {
                        $updates['brand_id'] = $brand->id;
                    }
                    if ($model && (int) $device->product_model_id !== (int) $model->id) {
                        $updates['product_model_id'] = $model->id;
                    }

                    if ($updates !== []) {
                        DB::table('devices')->where('id', $device->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Links are additive metadata and must not be removed on rollback.
    }
};
