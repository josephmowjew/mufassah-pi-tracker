<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog and inventory records converted from hard deletes to soft
     * deletes. These are the tables where a vanished row previously destroyed
     * or orphaned stock with no way back; `deleted_at` keeps the row and its
     * history recoverable.
     *
     * Deliberately excluded: telemetry (`device_locations`, `geofence_events`)
     * and per-user ephemera (`notifications`). Soft-deleting high-volume
     * telemetry would bloat the table and add a predicate to every read.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'device_categories',
        'brands',
        'product_models',
        'inventory_batches',
        'inventory_receipts',
        'inventory_movements',
        'stock_transfer_requests',
        'stock_transfer_request_items',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'deleted_at')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'deleted_at')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
