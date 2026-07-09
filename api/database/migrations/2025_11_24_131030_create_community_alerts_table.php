<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theft_report_id')->constrained('theft_reports')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            // Alert status
            $table->enum('status', ['active', 'paused', 'resolved', 'expired'])->default('active');

            // Broadcast settings
            $table->decimal('broadcast_latitude', 10, 8);
            $table->decimal('broadcast_longitude', 11, 8);
            $table->unsignedInteger('alert_radius_km')->default(10);
            $table->boolean('opt_in_notifications')->default(true);

            // Bounty information
            $table->decimal('bounty_amount', 12, 2)->nullable();
            $table->string('bounty_currency', 3)->default('MWK');
            $table->enum('bounty_status', ['none', 'offered', 'claimed', 'paid'])->default('none');

            // Engagement metrics
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('share_count')->default(0);
            $table->unsignedInteger('tips_count')->default(0);

            // Timing
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index(['broadcast_latitude', 'broadcast_longitude']);
            $table->index('bounty_status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_alerts');
    }
};
