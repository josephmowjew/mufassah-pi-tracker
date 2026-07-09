<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('device_categories')->cascadeOnDelete();
            $table->string('brand', 100);
            $table->string('model', 150);
            $table->string('color', 50)->nullable();
            $table->unsignedInteger('quantity_received');
            $table->decimal('buying_price', 12, 2);
            $table->decimal('target_selling_price', 12, 2);
            $table->date('purchase_date')->nullable();
            $table->string('purchase_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'brand', 'model']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
