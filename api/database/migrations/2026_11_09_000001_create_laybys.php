<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layby_branch_policies', function (Blueprint $table) {
            $table->foreignId('branch_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('deposit_percent')->default(20);
            $table->unsignedSmallInteger('term_days')->default(30);
            $table->unsignedSmallInteger('vat_basis_points')->default(1750);
            $table->string('review_reference')->nullable();
            $table->json('merchant_channels');
            $table->foreignId('updated_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('laybys', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('customer_name');
            $table->string('serial_number');
            $table->string('description');
            $table->string('reserved_from_status', 20);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedSmallInteger('vat_basis_points');
            $table->unsignedTinyInteger('deposit_percent');
            $table->unsignedSmallInteger('term_days');
            $table->text('accepted_terms');
            $table->date('original_due_date');
            $table->date('due_date');
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'due_date']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('reserved_layby_id')->nullable()->unique()->constrained('laybys')->restrictOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'mobile_money', 'card', 'bank_transfer', 'credit'])
                ->nullable()->default('cash')->change();
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded', 'cancelled', 'refund_processing'])
                ->nullable()->default('pending')->change();
        });

        Schema::create('layby_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layby_id')->constrained('laybys')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('layby_entries')->restrictOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('tender', 24)->nullable();
            $table->string('merchant_channel')->nullable();
            $table->string('reference')->nullable();
            $table->string('reference_key', 64)->nullable()->unique();
            $table->text('evidence')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->nullable();
            // Nullable so MariaDB strict mode accepts the column; the model
            // sets created_at through Eloquent on every insert.
            $table->timestamp('created_at')->nullable();
            $table->index(['layby_id', 'kind']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['shift_id', 'kind']);
        });

        Schema::create('layby_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 80);
            $table->string('fingerprint', 64);
            $table->json('result')->nullable();
            $table->unique(['actor_id', 'request_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('laybys')->exists()) {
            throw new RuntimeException('Layby agreements exist. Disable new agreements and retain the ledger.');
        }

        Schema::dropIfExists('layby_actions');
        Schema::dropIfExists('layby_entries');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['reserved_layby_id']);
            $table->dropUnique(['reserved_layby_id']);
            $table->dropColumn('reserved_layby_id');
        });
        Schema::dropIfExists('laybys');
        Schema::dropIfExists('layby_branch_policies');
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'mobile_money', 'card', 'bank_transfer', 'credit'])
                ->default('cash')->change();
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded', 'cancelled'])
                ->nullable()->default('pending')->change();
        });
    }
};
