<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A break is a row that lives *inside* a shift's span; it never touches
     * `shifts.status`. `User::activeShift()` is scoped to `status = 'active'`,
     * so encoding "on break" as a status would make `current` report no active
     * shift, `end` return 404 and `start` create a second concurrent shift.
     *
     * The time columns are DATETIME, not TIMESTAMP, on purpose. MariaDB with
     * explicit_defaults_for_timestamp=0 silently gives the first non-nullable
     * TIMESTAMP column a DEFAULT/ON UPDATE CURRENT_TIMESTAMP clause, which is
     * how every shift used to report a zero-second duration after completion.
     * TimestampColumnIntegrityTest fails the suite if such a column appears.
     */
    public function up(): void
    {
        Schema::create('shift_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['lunch', 'tea', 'comfort', 'prayer', 'other'])->default('other');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Serves both "the open break for this shift" and "every break on
            // this shift, oldest first" without a second index.
            $table->index(['shift_id', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_breaks');
    }
};
