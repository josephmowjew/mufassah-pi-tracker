<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number')->unique();
            $table->string('movement_type', 50);
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('device_categories')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->foreignId('previous_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_backfilled')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['movement_type', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['from_branch_id', 'to_branch_id']);
            $table->index(['device_id', 'created_at']);
            $table->index(['inventory_batch_id', 'created_at']);
            $table->index(['inventory_receipt_id', 'created_at']);
            $table->index(['sale_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['brand', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
