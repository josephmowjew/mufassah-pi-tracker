<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('quantity');
            $table->decimal('unit_price', 14, 2)->nullable()->after('unit_cost');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_price');
            $table->decimal('total_amount', 14, 2)->nullable()->after('total_cost');
            $table->decimal('profit_amount', 14, 2)->nullable()->after('total_amount');

            $table->index(['movement_type', 'branch_id', 'created_at'], 'inventory_movements_financial_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_financial_index');
            $table->dropColumn(['unit_cost', 'unit_price', 'total_cost', 'total_amount', 'profit_amount']);
        });
    }
};
