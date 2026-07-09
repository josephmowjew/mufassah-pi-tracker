<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('transaction_id', 100)->unique();
            $table->enum('type', ['credit', 'debit', 'refund']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MWK');
            $table->decimal('balance_before', 10, 2)->nullable();
            $table->decimal('balance_after', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
