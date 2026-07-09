<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sale_items')
            ->where(function ($query) {
                $query->whereNull('cost_price')
                    ->orWhereNull('selling_price')
                    ->orWhereNull('profit_amount');
            })
            ->orderBy('id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $device = $item->device_id
                        ? DB::table('devices')->where('id', $item->device_id)->first(['purchase_price'])
                        : null;

                    $costPrice = round((float) ($item->cost_price ?? $device?->purchase_price ?? 0), 2);
                    $sellingPrice = round((float) ($item->selling_price ?? $item->unit_price ?? 0), 2);
                    $discount = round((float) ($item->discount ?? 0), 2);

                    DB::table('sale_items')
                        ->where('id', $item->id)
                        ->update([
                            'cost_price' => $costPrice,
                            'selling_price' => $sellingPrice,
                            'profit_amount' => round($sellingPrice - $discount - $costPrice, 2),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Keep historical financial snapshots intact on rollback.
    }
};
