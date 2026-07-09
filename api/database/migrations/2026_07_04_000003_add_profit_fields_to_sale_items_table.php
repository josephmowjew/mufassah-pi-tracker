<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->nullable()->after('total_price');
            }
            if (! Schema::hasColumn('sale_items', 'selling_price')) {
                $table->decimal('selling_price', 12, 2)->nullable()->after('cost_price');
            }
            if (! Schema::hasColumn('sale_items', 'profit_amount')) {
                $table->decimal('profit_amount', 12, 2)->nullable()->after('selling_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $dropColumns = array_intersect(
                ['cost_price', 'selling_price', 'profit_amount'],
                Schema::getColumnListing('sale_items')
            );

            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
