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
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('shape_type', ['circle', 'polygon'])->default('circle');
            $table->decimal('center_latitude', 10, 8);
            $table->decimal('center_longitude', 11, 8);
            $table->unsignedInteger('radius_meters');
            $table->json('polygon_coordinates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('notify_on_entry')->default(false);
            $table->boolean('notify_on_exit')->default(true);
            $table->json('active_days')->nullable();
            $table->time('active_time_start')->nullable();
            $table->time('active_time_end')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('device_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
