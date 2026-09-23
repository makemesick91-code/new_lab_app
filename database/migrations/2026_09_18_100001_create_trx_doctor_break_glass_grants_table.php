<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — bounded emergency
 * access for ONE doctor whose trusted-device path is unavailable.
 *
 * Additive only. Nothing existing is altered or dropped, and an empty table
 * grants nobody anything: the gate reads it only to find an ACTIVE row, so a
 * deployment that never files a grant behaves exactly as it does today.
 *
 * WHY THE TARGET COLUMN IS `user_id` AND NOT `doctor_id`.
 *
 * The authentication gate narrows on the USER ACCOUNT (`appliesTo()` asks
 * `$user->hasRole('Doctor')`, `inEnforcementScope()` keys on `$user->id`), so
 * a grant that is supposed to admit a login has to be keyed the same way. The
 * two id spaces are NOT interchangeable and have collided in this production
 * database before, which is why `doctor_id` is carried alongside as reporting
 * provenance and is never what the gate matches on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_break_glass_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * The admitted account. RESTRICT rather than cascade: an emergency
             * grant is audit evidence about who was let in without a device,
             * and deleting a user must not silently erase that record.
             */
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Provenance for the operator UI only. Nullable because the gate
            // never needs it and an unlinked account must not block a grant.
            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('mst_doctors')
                ->nullOnDelete();

            // Bounded by config('doctor_access.reason'), enforced in the
            // FormRequest and re-asserted in the granting transaction.
            $table->string('reason', 1000);

            $table->foreignId('granted_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('granted_at');

            /*
             * THE BOUND. Indexed because every protected request of an admitted
             * doctor asks "is there an unexpired, unrevoked grant for this
             * account", and that question must not become a scan.
             */
            $table->timestamp('expires_at');

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('revoked_reason', 1000)->nullable();

            // First exercise of the grant. Evidence that an emergency window
            // was actually used, so an unused one can be told apart from one
            // that carried a clinical session.
            $table->timestamp('first_used_at')->nullable();

            $table->timestamps();

            // The gate's lookup, in its exact shape.
            $table->index(['user_id', 'expires_at'], 'trx_dbg_user_expires_index');
            $table->index('expires_at', 'trx_dbg_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_break_glass_grants');
    }
};
