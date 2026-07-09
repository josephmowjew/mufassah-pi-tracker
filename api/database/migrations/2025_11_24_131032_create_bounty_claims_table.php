<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_alert_id')->constrained('community_alerts')->cascadeOnDelete();
            $table->foreignId('claimant_id')->constrained('users');

            // Claim details
            $table->enum('claim_type', ['recovered_device', 'led_to_recovery', 'key_information']);
            $table->text('description');
            $table->json('evidence_photos')->nullable();

            // Recovery details
            $table->decimal('recovery_latitude', 10, 8)->nullable();
            $table->decimal('recovery_longitude', 11, 8)->nullable();
            $table->string('recovery_address', 500)->nullable();
            $table->timestamp('recovery_date')->nullable();

            // Claim status
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');

            // Review
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Payment
            $table->decimal('claim_amount', 12, 2)->nullable(); // Amount claimed (might differ from bounty)
            $table->decimal('approved_amount', 12, 2)->nullable(); // Amount approved
            $table->string('payment_method', 50)->nullable(); // airtel_money, tnm_mpamba, bank_transfer
            $table->string('payment_account', 100)->nullable(); // Phone number or bank account
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();

            // Feedback
            $table->unsignedTinyInteger('owner_rating')->nullable(); // 1-5 stars
            $table->text('owner_feedback')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('community_alert_id');
            $table->index('claimant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounty_claims');
    }
};
