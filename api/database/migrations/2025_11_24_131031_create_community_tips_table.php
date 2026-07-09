<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_alert_id')->constrained('community_alerts')->cascadeOnDelete();
            $table->foreignId('tipster_id')->nullable()->constrained('users')->nullOnDelete();

            // Tip details
            $table->enum('tip_type', ['sighting', 'location', 'suspect_info', 'recovery', 'other'])->default('sighting');
            $table->text('description');

            // Location of sighting
            $table->decimal('spotted_latitude', 10, 8)->nullable();
            $table->decimal('spotted_longitude', 11, 8)->nullable();
            $table->string('spotted_address', 500)->nullable();
            $table->timestamp('spotted_at')->nullable();

            // Contact preferences
            $table->boolean('is_anonymous')->default(false);
            $table->enum('contact_preference', ['app', 'phone', 'anonymous'])->default('app');
            $table->string('contact_info', 255)->nullable(); // Encrypted phone/email if provided

            // Evidence
            $table->json('photos')->nullable();

            // Verification
            $table->enum('status', ['pending', 'reviewing', 'verified', 'false_lead'])->default('pending');
            $table->boolean('is_helpful')->default(false);
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('community_alert_id');
            $table->index('tipster_id');
            $table->index('status');
            $table->index('tip_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_tips');
    }
};
