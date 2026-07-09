<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 100)->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->string('event', 100)->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('log_name');
            $table->index('subject_type');
            $table->index('causer_type');
            $table->index('event');
            $table->index('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
