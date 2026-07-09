<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theft_report_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theft_report_id')->constrained('theft_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('old_status', ['reported', 'investigating', 'recovered', 'closed', 'false_alarm'])->nullable();
            $table->enum('new_status', ['reported', 'investigating', 'recovered', 'closed', 'false_alarm'])->nullable();
            $table->text('notes');
            $table->boolean('is_public')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index('theft_report_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theft_report_updates');
    }
};
