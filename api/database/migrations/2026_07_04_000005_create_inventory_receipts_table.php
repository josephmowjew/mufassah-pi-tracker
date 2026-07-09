<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('supplier')->nullable();
            $table->string('reference_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('purchase_location')->nullable();
            $table->string('status', 30)->default('received');
            $table->text('notes')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipts');
    }
};
