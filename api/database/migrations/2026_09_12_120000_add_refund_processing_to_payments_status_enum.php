<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add `refund_processing` to the payments status enum.
     *
     * A refund is issued through PayChangu's Disbursement API, which is
     * asynchronous: the payout comes back as "pending" and settles later. The
     * payment therefore needs a state that says "the money is on its way out"
     * without claiming it has arrived, which the existing values cannot express.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite has no ENUM; the column is a VARCHAR with a CHECK constraint
            // that is rebuilt from the base migration on every test run, and that
            // migration already lists the new value.
            return;
        }

        // Appended at the end of the list so MySQL can widen the column in place
        // instead of rebuilding the table.
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled', 'refund_processing') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Narrowing an ENUM maps any row still holding the removed value to the
        // empty string, which MySQL treats as an invalid-value error member. Fold
        // in-flight refunds back to completed first so no row is silently lost.
        DB::statement("UPDATE payments SET status = 'completed' WHERE status = 'refund_processing'");

        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
