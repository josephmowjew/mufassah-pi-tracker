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
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['admin', 'guardian', 'member', 'child'])->default('member');
            $table->string('nickname', 50)->nullable()->comment('Display name in family');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'active', 'removed'])->default('pending');
            $table->json('permissions')->nullable()->comment('Granular permission overrides');
            $table->boolean('location_sharing_enabled')->default(true);
            $table->boolean('receive_family_alerts')->default(true);
            $table->timestamps();

            $table->unique(['family_id', 'user_id']);
            $table->index(['family_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
