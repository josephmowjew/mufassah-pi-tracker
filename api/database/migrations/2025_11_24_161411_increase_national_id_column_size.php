<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The national_id field is encrypted, which produces a much longer string
     * than the original value. We need to increase the column size to accommodate
     * the encrypted value (JSON with iv, value, mac, tag).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted values are much longer than the original
            // A 50-char national_id becomes ~200+ chars when encrypted
            $table->string('national_id', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('national_id', 50)->nullable()->change();
        });
    }
};
