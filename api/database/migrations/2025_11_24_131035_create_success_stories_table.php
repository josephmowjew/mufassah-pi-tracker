<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('success_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_alert_id')->constrained('community_alerts')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');

            // Story content
            $table->string('title', 255);
            $table->text('story');
            $table->json('photos')->nullable();

            // Device info snapshot (for display even if device deleted)
            $table->string('device_brand', 100)->nullable();
            $table->string('device_model', 100)->nullable();
            $table->string('device_type', 50)->nullable();

            // Recovery details
            $table->unsignedInteger('days_to_recover')->nullable();
            $table->decimal('bounty_paid', 12, 2)->nullable();
            $table->string('recovery_city', 100)->nullable();

            // Publication
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();

            // Engagement
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);

            // Moderation
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('is_published');
            $table->index('is_featured');
            $table->index('published_at');
            $table->index('likes_count');
        });

        // Pivot table for likes
        Schema::create('success_story_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('success_story_id')->constrained('success_stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['success_story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('success_story_likes');
        Schema::dropIfExists('success_stories');
    }
};
