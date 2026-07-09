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
        Schema::create('geofence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geofence_id')->constrained('geofences')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('device_locations')->cascadeOnDelete();
            $table->enum('event_type', ['entry', 'exit']);
            $table->decimal('distance_from_center', 10, 2);
            $table->boolean('alert_sent')->default(false);
            $table->timestamp('event_time');
            $table->timestamp('created_at')->nullable();

            $table->index('geofence_id');
            $table->index('device_id');
            $table->index('event_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geofence_events');
    }
};
