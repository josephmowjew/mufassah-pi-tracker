<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->string('currency', 3)->default('MWK');
            $table->integer('max_devices')->default(1);
            $table->integer('max_geofences_per_device')->default(5);
            $table->integer('location_history_days')->default(30);
            $table->boolean('real_time_tracking')->default(true);
            $table->boolean('theft_alerts')->default(true);
            $table->boolean('geofence_alerts')->default(true);
            $table->boolean('priority_support')->default(false);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
