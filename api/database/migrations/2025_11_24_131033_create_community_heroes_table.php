<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_heroes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Statistics
            $table->unsignedInteger('successful_recoveries')->default(0);
            $table->unsignedInteger('tips_submitted')->default(0);
            $table->unsignedInteger('tips_verified')->default(0);
            $table->unsignedInteger('claims_approved')->default(0);

            // Earnings
            $table->decimal('total_bounties_earned', 12, 2)->default(0);

            // Reputation
            $table->unsignedInteger('reputation_score')->default(0);
            $table->json('badges')->nullable(); // Array of earned badges
            $table->unsignedInteger('rank')->nullable();

            // Visibility
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_public')->default(true); // Allow hiding from leaderboard
            $table->string('display_name', 100)->nullable(); // Optional alias

            // Monthly tracking
            $table->unsignedInteger('monthly_recoveries')->default(0);
            $table->unsignedInteger('monthly_tips')->default(0);
            $table->date('stats_reset_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('reputation_score');
            $table->index('rank');
            $table->index('is_featured');
            $table->index('successful_recoveries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_heroes');
    }
};
