<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_action_challenges')) {
            $indexes = collect(Schema::getIndexes('inventory_action_challenges'))
                ->pluck('name')
                ->all();

            Schema::table('inventory_action_challenges', function (Blueprint $table) use ($indexes) {
                if (! in_array('inventory_action_challenges_requested_by_action_created_at_index', $indexes, true)) {
                    $table->index(
                        ['requested_by_id', 'action', 'created_at'],
                        'inventory_action_challenges_requested_by_action_created_at_index'
                    );
                }

                if (! in_array('inventory_action_challenges_expires_consumed_invalidated_index', $indexes, true)) {
                    $table->index(
                        ['expires_at', 'consumed_at', 'invalidated_at'],
                        'inventory_action_challenges_expires_consumed_invalidated_index'
                    );
                }

                if (! in_array('inventory_action_challenges_payload_hash_index', $indexes, true)) {
                    $table->index('payload_hash', 'inventory_action_challenges_payload_hash_index');
                }
            });

            return;
        }

        Schema::create('inventory_action_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 80);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('pin_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(
                ['requested_by_id', 'action', 'created_at'],
                'inventory_action_challenges_requested_by_action_created_at_index'
            );
            $table->index(
                ['expires_at', 'consumed_at', 'invalidated_at'],
                'inventory_action_challenges_expires_consumed_invalidated_index'
            );
            $table->index('payload_hash', 'inventory_action_challenges_payload_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_action_challenges');
    }
};
