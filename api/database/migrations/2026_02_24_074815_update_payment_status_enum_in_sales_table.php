<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Map old values to new values before changing the enum
        DB::statement("UPDATE sales SET payment_status = 'completed' WHERE payment_status = 'paid'");
        DB::statement("UPDATE sales SET payment_status = 'pending' WHERE payment_status = 'partial'");

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Change the enum to match PaymentStatus enum values
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_status ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("UPDATE sales SET payment_status = 'paid' WHERE payment_status = 'completed'");

            return;
        }

        // Revert to old enum values
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_status ENUM('pending', 'paid', 'partial', 'refunded') DEFAULT 'pending'");

        // Map values back (best effort)
        DB::statement("UPDATE sales SET payment_status = 'paid' WHERE payment_status = 'completed'");
        DB::statement("UPDATE sales SET payment_status = 'partial' WHERE payment_status = 'failed' AND payment_status = 'cancelled'");
    }
};
