<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('reserved_invoice_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('invoices')
                ->nullOnDelete();
            $table->index(['inventory_status', 'reserved_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['reserved_invoice_id']);
            $table->dropIndex(['inventory_status', 'reserved_invoice_id']);
            $table->dropColumn('reserved_invoice_id');
        });
    }
};
