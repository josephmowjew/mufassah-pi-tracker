<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theft_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices');
            $table->foreignId('reported_by')->constrained('users');
            $table->enum('status', ['reported', 'investigating', 'recovered', 'closed', 'false_alarm'])->default('reported');
            $table->timestamp('theft_date');
            $table->string('theft_location', 500)->nullable();
            $table->decimal('theft_latitude', 10, 8)->nullable();
            $table->decimal('theft_longitude', 11, 8)->nullable();
            $table->text('description');
            $table->string('police_report_number', 100)->nullable();
            $table->string('police_station', 255)->nullable();
            $table->string('officer_name', 255)->nullable();
            $table->string('officer_contact', 50)->nullable();
            $table->json('evidence_photos')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recovered_at')->nullable();
            $table->string('recovery_location', 500)->nullable();
            $table->text('recovery_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('device_id');
            $table->index('reported_by');
            $table->index('status');
            $table->index('theft_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theft_reports');
    }
};
