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
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('purchase_price', 10, 2)->nullable()->change();
            $table->date('purchase_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('purchase_price', 10, 2)->nullable(false)->change();
            $table->date('purchase_date')->nullable(false)->change();
        });
    }
};
