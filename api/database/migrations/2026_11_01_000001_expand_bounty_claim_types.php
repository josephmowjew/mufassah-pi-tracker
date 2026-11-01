<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TYPES = ['recovered_device', 'led_to_recovery', 'key_information'];

    private const CURRENT_TYPES = ['recovery', 'tip_leading_to_recovery', 'partial_recovery'];

    public function up(): void
    {
        // Append rather than replace: keep existing classifications and MySQL
        // enum ordinals intact while allowing the API's current claim types.
        $this->setAllowedTypes([...self::LEGACY_TYPES, ...self::CURRENT_TYPES]);
    }

    public function down(): void
    {
        if (DB::table('bounty_claims')->whereIn('claim_type', self::CURRENT_TYPES)->exists()) {
            throw new RuntimeException('Cannot remove bounty claim types while claims still use them.');
        }

        $this->setAllowedTypes(self::LEGACY_TYPES);
    }

    private function setAllowedTypes(array $types): void
    {
        Schema::table('bounty_claims', function (Blueprint $table) use ($types) {
            $table->enum('claim_type', $types)->change();

            if (DB::getDriverName() === 'sqlite') {
                // SQLite rebuilds this table to alter an enum CHECK. Restate
                // the other enum so its CHECK is not lost during introspection.
                $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])
                    ->default('pending')->change();
            }
        });
    }
};
