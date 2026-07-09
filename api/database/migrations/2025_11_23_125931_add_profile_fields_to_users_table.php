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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 20)->unique()->nullable()->after('email');
            $table->string('national_id', 50)->unique()->nullable()->after('phone_number');
            $table->text('address')->nullable()->after('national_id');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('avatar', 255)->nullable()->after('city');
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->softDeletes();

            $table->index('phone_number');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone_number']);
            $table->dropIndex(['created_at']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone_number',
                'national_id',
                'address',
                'city',
                'avatar',
                'two_factor_secret',
                'two_factor_recovery_codes',
            ]);
        });
    }
};
