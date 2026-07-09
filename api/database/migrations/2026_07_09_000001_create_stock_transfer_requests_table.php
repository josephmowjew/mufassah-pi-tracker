<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'to_branch_id']);
            $table->index(['from_branch_id', 'status']);
        });

        Schema::create('stock_transfer_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['stock_transfer_request_id', 'device_id'], 'str_items_request_device_unique');
            $table->unique('device_id', 'str_items_device_unique');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('stock_transfer_request_id')->nullable()->after('inventory_batch_id')->constrained()->nullOnDelete();
            $table->index('stock_transfer_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_request_id');
        });
        Schema::dropIfExists('stock_transfer_request_items');
        Schema::dropIfExists('stock_transfer_requests');
    }
};
