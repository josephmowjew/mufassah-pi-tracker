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
        Schema::create('communications_settings', function (Blueprint $table) {
            $table->id();
            $table->json('admin_emails');
            $table->boolean('sale_notifications_enabled')->default(true);
            $table->boolean('shift_summary_notifications_enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communications_settings');
    }
};
