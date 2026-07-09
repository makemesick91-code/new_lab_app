<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-PRE-68-45 Scope G — additive nullable `users.branch_id` (home branch). Lets a
 * branch-scoped role (Kepala Cabang) be pinned to one branch: BranchContext already
 * Schema::hasColumn-guards this column, so a user with branch_id set resolves to
 * that branch and a NULL branch_id falls through to the existing MAIN default. No
 * existing user is assigned a branch here, so runtime behavior is unchanged until a
 * branch is explicitly set. Additive only — nullOnDelete FK, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('email')
                    ->constrained('mst_branches')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }
};
