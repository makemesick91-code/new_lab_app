<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — cryptographic identity
 * columns on the Phase 2 device registry.
 *
 * Additive only. Phase 2's `identity_state` already distinguishes a typed row
 * from a proven device; Phase 3 adds the material that makes proof possible:
 *
 *   public_key      the device's X.509 SubjectPublicKeyInfo, base64 DER. PUBLIC
 *                   material only — the private key never leaves the Android
 *                   Keystore and is never transmitted, stored or logged.
 *   key_algorithm   how to verify (EC P-256 / SHA256withECDSA).
 *   verified_at/by  when the device first proved possession, and which admin
 *                   approved the enrolment that bound the key.
 *   last_verified_at  most recent successful challenge-response.
 *
 * `enrollment_status` is deliberately SEPARATE from `status`:
 *   status            = the administrative decision (active/disabled/revoked)
 *   enrollment_status = where the device is in the pairing protocol
 * Neither overrides the other; the proof endpoint requires BOTH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_doctor_devices', function (Blueprint $table) {
            $table->string('enrollment_status', 20)->default('not_enrolled')->after('identity_state');

            // Public key material. Nullable: a Phase 2 row has never enrolled.
            $table->text('public_key')->nullable()->after('public_key_fingerprint');
            $table->string('key_algorithm', 40)->nullable()->after('public_key');

            $table->timestamp('enrollment_requested_at')->nullable()->after('key_algorithm');
            $table->timestamp('verified_at')->nullable()->after('enrollment_requested_at');
            $table->foreignId('verified_by')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('last_verified_at')->nullable()->after('verified_by');

            $table->index('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::table('mst_doctor_devices', function (Blueprint $table) {
            $table->dropIndex(['enrollment_status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'enrollment_status',
                'public_key',
                'key_algorithm',
                'enrollment_requested_at',
                'verified_at',
                'last_verified_at',
            ]);
        });
    }
};
