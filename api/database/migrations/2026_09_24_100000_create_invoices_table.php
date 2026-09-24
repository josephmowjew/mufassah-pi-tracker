<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['sent', 'expired', 'cancelled', 'converted'])->default('sent');
            $table->string('public_token', 80)->unique();
            $table->string('recipient_email');

            // dateTime, not timestamp: production runs MariaDB 10.6 with
            // explicit_defaults_for_timestamp=0. Under that setting a `timestamp`
            // declared NOT NULL without an explicit DEFAULT is given the implicit
            // zero-date default '0000-00-00 00:00:00', which strict mode rejects
            // outright (SQLSTATE 42000 / error 1067 "Invalid default value for
            // expires_at"). DATETIME carries no implicit zero-date default, so it
            // creates cleanly on MariaDB and MySQL alike. The Invoice model casts
            // both columns to 'datetime', so reads and writes are unchanged.
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');

            $table->boolean('vat_applicable')->default(true);
            $table->decimal('tax_rate', 5, 2)->default(17.5);
            $table->string('tax_label', 40)->default('VAT');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('MWK');
            $table->text('notes')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('converted_sale_id')->nullable()->unique()->constrained('sales')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
