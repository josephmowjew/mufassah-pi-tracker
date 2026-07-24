<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('employment_type', 80)->default('Full-time');
            $table->text('description');
            $table->string('application_email')->nullable();
            $table->date('closing_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
            $table->index('closing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
