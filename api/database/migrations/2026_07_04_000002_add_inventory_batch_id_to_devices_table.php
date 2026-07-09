<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'inventory_batch_id')) {
                $table->foreignId('inventory_batch_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('inventory_batches')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'inventory_batch_id')) {
                $table->dropConstrainedForeignId('inventory_batch_id');
            }
        });
    }
};
