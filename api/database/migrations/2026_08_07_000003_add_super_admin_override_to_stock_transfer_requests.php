<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->boolean('is_override')->default(false)->after('status');
            $table->foreignId('overridden_by_id')->nullable()->after('decided_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by_id');
            $table->dropColumn(['is_override', 'overridden_at']);
        });
    }
};
