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
            $indexes = collect(Schema::getIndexes('devices'))->pluck('name')->toArray();

            if (! in_array('devices_branch_id_inventory_status_index', $indexes)) {
                $table->index(['branch_id', 'inventory_status']);
            }

            if (! in_array('devices_branch_id_inventory_status_category_id_index', $indexes)) {
                $table->index(['branch_id', 'inventory_status', 'category_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('devices'))->pluck('name')->toArray();

            if (in_array('devices_branch_id_inventory_status_index', $indexes)) {
                $table->dropIndex(['branch_id', 'inventory_status']);
            }

            if (in_array('devices_branch_id_inventory_status_category_id_index', $indexes)) {
                $table->dropIndex(['branch_id', 'inventory_status', 'category_id']);
            }
        });
    }
};
