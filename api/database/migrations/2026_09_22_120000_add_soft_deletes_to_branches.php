<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches were hard-deleted, which contradicted the project's soft-delete policy:
 * the row and its history vanished permanently.
 *
 * `branches.code` carries a unique index and MySQL has no partial unique index, so
 * a trashed branch keeps occupying its code. Rather than leaving the code
 * permanently unusable, BranchController::store restores the trashed row when the
 * same code is submitted again - which also keeps the branch id stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branches', 'deleted_at')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('branches', 'deleted_at')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
