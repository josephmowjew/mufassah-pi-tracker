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
            // Add foreign keys for product catalog (nullable for backward compatibility)
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            $table->foreignId('product_model_id')->nullable()->after('brand_id')->constrained('product_models')->nullOnDelete();

            // Add index for faster lookups
            $table->index(['brand_id', 'product_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['product_model_id']);
            $table->dropIndex(['brand_id', 'product_model_id']);
            $table->dropColumn(['brand_id', 'product_model_id']);
        });
    }
};
