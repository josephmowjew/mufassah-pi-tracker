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
        Schema::create('iot_modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_id', 50)->unique();
            $table->string('api_token', 80)->unique();
            $table->string('sim_number', 20)->unique()->nullable();
            $table->string('imei', 20)->unique()->nullable();
            $table->enum('modem_type', ['GPS', 'GSM', 'GPS_GSM', 'WIFI']);
            $table->string('firmware_version', 20)->nullable();
            $table->string('hardware_version', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'tampered', 'offline', 'maintenance'])->default('inactive');
            $table->unsignedTinyInteger('battery_level')->nullable()->comment('0-100 percentage');
            $table->tinyInteger('signal_strength')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamp('last_ping')->nullable();
            $table->timestamp('last_location_update')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for commonly queried columns
            $table->index('module_id');
            $table->index('status');
            $table->index('last_ping');
            $table->index('last_location_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_modules');
    }
};
