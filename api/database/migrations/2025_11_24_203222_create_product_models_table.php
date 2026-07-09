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
        Schema::create('product_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('base_price', 12, 2)->nullable()->comment('Suggested retail price in MWK');
            $table->json('specifications')->nullable()->comment('JSON object with product specs');
            $table->json('available_colors')->nullable()->comment('Array of available color options');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
            $table->index(['brand_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_models');
    }
};
