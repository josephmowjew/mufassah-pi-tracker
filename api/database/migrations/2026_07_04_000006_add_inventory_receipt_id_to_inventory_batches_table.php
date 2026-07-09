<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->foreignId('inventory_receipt_id')
                ->nullable()
                ->after('id')
                ->constrained('inventory_receipts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_receipt_id');
        });
    }
};
