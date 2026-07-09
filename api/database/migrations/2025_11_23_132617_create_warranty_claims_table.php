<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->string('claim_number', 50)->unique();
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing', 'completed'])->default('pending');
            $table->text('issue_description');
            $table->string('issue_category', 100);
            $table->json('evidence_photos')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('resolution_type', 100)->nullable();
            $table->decimal('repair_cost', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('warranty_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
