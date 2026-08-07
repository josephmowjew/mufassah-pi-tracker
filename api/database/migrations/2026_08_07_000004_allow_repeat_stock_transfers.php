<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_request_items', function (Blueprint $table) {
            $table->index('device_id', 'str_items_device_index');
        });

        Schema::table('stock_transfer_request_items', function (Blueprint $table) {
            $table->dropUnique('str_items_device_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_request_items', function (Blueprint $table) {
            $table->dropIndex('str_items_device_index');
        });

        Schema::table('stock_transfer_request_items', function (Blueprint $table) {
            $table->unique('device_id', 'str_items_device_unique');
        });
    }
};
