<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('payment_number', 50)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MWK');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded', 'cancelled'])->default('pending');
            $table->enum('payment_method', ['mobile_money', 'bank_transfer', 'cash', 'card']);
            $table->string('payment_provider', 100)->nullable();
            $table->string('provider_transaction_id', 255)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('subscription_id');
            $table->index('status');
            $table->index('payment_method');
            $table->index('paid_at');
        });

        // Add foreign key for last_payment_id in subscriptions
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('last_payment_id')
                ->references('id')
                ->on('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['last_payment_id']);
        });

        Schema::dropIfExists('payments');
    }
};
