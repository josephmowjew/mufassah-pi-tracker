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
        Schema::create('device_ownership_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('transfer_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('transferred_at');
            $table->timestamp('created_at')->nullable();

            // Indexes for commonly queried columns
            $table->index('device_id');
            $table->index('from_user_id');
            $table->index('to_user_id');
            $table->index('transferred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_ownership_history');
    }
};
