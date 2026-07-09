<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            // Home location
            $table->decimal('home_latitude', 10, 8)->nullable();
            $table->decimal('home_longitude', 11, 8)->nullable();
            $table->unsignedInteger('home_radius_km')->default(5);
            $table->string('home_label', 100)->nullable();

            // Work location
            $table->decimal('work_latitude', 10, 8)->nullable();
            $table->decimal('work_longitude', 11, 8)->nullable();
            $table->unsignedInteger('work_radius_km')->default(5);
            $table->string('work_label', 100)->nullable();

            // Custom location (e.g., school, church)
            $table->decimal('custom_latitude', 10, 8)->nullable();
            $table->decimal('custom_longitude', 11, 8)->nullable();
            $table->unsignedInteger('custom_radius_km')->default(5);
            $table->string('custom_label', 100)->nullable();

            // Notification preferences
            $table->json('notify_via')->nullable(); // push, sms, email, whatsapp
            $table->boolean('notify_with_bounty_only')->default(false);
            $table->unsignedInteger('min_bounty_amount')->nullable();

            // Quiet hours
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();

            // Stats
            $table->unsignedInteger('alerts_received')->default(0);
            $table->timestamp('last_alert_at')->nullable();

            $table->timestamps();

            // Indexes for geospatial queries
            $table->index(['home_latitude', 'home_longitude']);
            $table->index(['work_latitude', 'work_longitude']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_subscriptions');
    }
};
