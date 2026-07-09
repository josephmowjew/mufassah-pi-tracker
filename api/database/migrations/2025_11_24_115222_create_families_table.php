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
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 8)->unique()->comment('Invite code for joining');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('avatar')->nullable();
            $table->json('settings')->nullable()->comment('Family-wide preferences');
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
            $table->index('owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
