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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('unique_device_code', 50)->unique();
            $table->string('serial_number', 100)->unique();
            $table->foreignId('category_id')->constrained('device_categories')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('iot_module_id')->nullable()->constrained('iot_modules')->nullOnDelete();
            $table->string('brand', 100);
            $table->string('model', 150);
            $table->text('description')->nullable();
            $table->string('color', 50)->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_price', 10, 2);
            $table->string('purchase_location', 255)->nullable();
            $table->enum('status', ['active', 'stolen', 'recovered', 'inactive', 'maintenance'])->default('active');
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->unsignedBigInteger('last_location_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for commonly queried columns
            $table->index('unique_device_code');
            $table->index('serial_number');
            $table->index('owner_id');
            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
