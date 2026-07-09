<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Add inventory_status if not exists
            if (! Schema::hasColumn('devices', 'inventory_status')) {
                $table->enum('inventory_status', ['in_stock', 'reserved', 'sold', 'returned', 'faulty'])
                    ->default('in_stock')
                    ->after('status');
            }

            // Add sale_id if not exists
            if (! Schema::hasColumn('devices', 'sale_id')) {
                $table->unsignedBigInteger('sale_id')->nullable()->after('owner_id');
            }

            // Add sold_at if not exists
            if (! Schema::hasColumn('devices', 'sold_at')) {
                $table->timestamp('sold_at')->nullable()->after('sale_id');
            }

            // Add sold_by_id if not exists
            if (! Schema::hasColumn('devices', 'sold_by_id')) {
                $table->unsignedBigInteger('sold_by_id')->nullable()->after('sold_at');
            }
        });

        // Add foreign keys in a separate schema call
        Schema::table('devices', function (Blueprint $table) {
            // Get existing foreign keys
            $foreignKeys = collect(Schema::getForeignKeys('devices'))->pluck('name')->toArray();

            // Add sale_id foreign key if not exists
            if (! in_array('devices_sale_id_foreign', $foreignKeys) && Schema::hasColumn('devices', 'sale_id')) {
                $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            }

            // Add sold_by_id foreign key if not exists
            if (! in_array('devices_sold_by_id_foreign', $foreignKeys) && Schema::hasColumn('devices', 'sold_by_id')) {
                $table->foreign('sold_by_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        // Add index for inventory_status
        Schema::table('devices', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('devices'))->pluck('name')->toArray();
            if (! in_array('devices_inventory_status_index', $indexes) && Schema::hasColumn('devices', 'inventory_status')) {
                $table->index('inventory_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $foreignKeys = collect(Schema::getForeignKeys('devices'))->pluck('name')->toArray();
            if (in_array('devices_sale_id_foreign', $foreignKeys)) {
                $table->dropForeign(['sale_id']);
            }
            if (in_array('devices_sold_by_id_foreign', $foreignKeys)) {
                $table->dropForeign(['sold_by_id']);
            }

            $indexes = collect(Schema::getIndexes('devices'))->pluck('name')->toArray();
            if (in_array('devices_inventory_status_index', $indexes)) {
                $table->dropIndex(['inventory_status']);
            }

            $columns = Schema::getColumnListing('devices');
            $dropColumns = array_intersect(['inventory_status', 'sale_id', 'sold_at', 'sold_by_id'], $columns);
            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
