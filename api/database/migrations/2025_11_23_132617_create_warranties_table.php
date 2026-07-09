<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices')->cascadeOnDelete();
            $table->string('warranty_number', 50)->unique();
            $table->enum('status', ['active', 'expired', 'voided', 'claimed'])->default('active');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('duration_months');
            $table->text('coverage_details')->nullable();
            $table->json('terms_conditions')->nullable();
            $table->string('provider', 255)->default('Mufasah Electronics');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('device_id');
            $table->index('status');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
