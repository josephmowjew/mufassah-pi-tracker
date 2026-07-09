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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sold_by_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->enum('payment_method', ['cash', 'mobile_money', 'card', 'bank_transfer', 'credit'])->default('cash');
            $table->enum('payment_status', ['pending', 'paid', 'partial', 'refunded'])->default('pending');
            $table->string('payment_reference', 100)->nullable();
            $table->enum('status', ['draft', 'completed', 'cancelled', 'refunded'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('sale_date')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('sale_number');
            $table->index('customer_id');
            $table->index('sold_by_id');
            $table->index('status');
            $table->index('sale_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
