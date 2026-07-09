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
        Schema::create('device_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('iot_module_id')->nullable()->constrained('iot_modules')->nullOnDelete();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('altitude', 8, 2)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            // Indexes for high volume table
            $table->index('device_id');
            $table->index('recorded_at');
            $table->index(['device_id', 'recorded_at']);
        });

        // Add foreign key constraint for last_location_id in devices table
        Schema::table('devices', function (Blueprint $table) {
            $table->foreign('last_location_id')
                ->references('id')
                ->on('device_locations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['last_location_id']);
        });

        Schema::dropIfExists('device_locations');
    }
};
