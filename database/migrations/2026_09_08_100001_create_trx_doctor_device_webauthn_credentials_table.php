<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — the browser's half of the doctor device registry.
 *
 * WHY A NEW TABLE AND NOT A COLUMN ON `mst_doctor_devices`
 *
 * A device has ONE Android enrolment key, because the Clinic App owns the
 * keystore alias. A device may legitimately hold SEVERAL WebAuthn credentials —
 * one per browser profile that was enrolled on it — and each carries its own
 * signature counter, its own backup flags and its own revocation state. Folding
 * that into the device row would force those to be shared, and the first
 * consequence would be that revoking one browser revokes the tablet.
 *
 * WHY IT HANGS OFF THE DEVICE AND NOT OFF THE DOCTOR
 *
 * This is the whole conceptual point of the sprint. The credential proves WHICH
 * PIECE OF HARDWARE is present. Which DOCTOR may use that hardware is a
 * separate question answered by `mst_doctor_device_authorizations`, which
 * already supports many doctors per device. Binding a credential to a doctor
 * here would collapse those two questions back together and make a shared
 * clinic tablet impossible to model.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No private key. No shared secret. No reusable bearer token. WebAuthn's
 * security rests on the private key never leaving the authenticator, so there
 * is nothing here an attacker with a database dump could authenticate with:
 * `public_key` verifies a signature, it cannot produce one.
 *
 * ADDITIVE. Creates one table, alters nothing, and the Android enrolment path
 * does not read it — so this migration cannot change how a Clinic App login
 * behaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('doctor_device_id')
                ->constrained('mst_doctor_devices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * The credential id as base64url, exactly as the browser reports
             * `rawId`. Unique GLOBALLY rather than per device: a credential id
             * identifies one key pair on one authenticator, so the same id
             * appearing under two devices would mean one of the two records is
             * a lie about which hardware is present.
             *
             * 1400 chars covers the spec's 1023-byte ceiling once base64url
             * expansion is applied; real platform authenticators emit far less.
             */
            $table->string('credential_id', 1400)->unique();

            // COSE_Key, base64. Verifies signatures; cannot create them.
            $table->text('public_key');

            /*
             * Authenticator signature counter. Zero is a VALID and common
             * value — many platform authenticators never increment it — so a
             * non-increasing counter is not by itself proof of cloning. It is
             * stored because the spec defines it and because a counter that
             * goes BACKWARDS on an authenticator that was previously
             * incrementing is worth refusing.
             */
            $table->unsignedBigInteger('signature_counter')->default(0);

            // Authenticator model identifier. Diagnostic only, never a gate:
            // an AAGUID is self-asserted unless attestation is verified.
            $table->string('aaguid', 64)->nullable();

            $table->json('transports')->nullable();

            // 'platform' | 'cross-platform' | null. A REQUEST outcome, not proof.
            $table->string('attachment', 20)->nullable();

            // Was a human verified (biometric/PIN) at registration?
            $table->boolean('user_verified')->default(false);

            /*
             * The flags that actually answer "is this key confined to this
             * device". Nullable because an authenticator that reports neither
             * has told us nothing, and recording a null is honest where
             * recording false would be an invented measurement.
             */
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_state')->nullable();

            // device_bound | backup_eligible | unknown. See config/webauthn.php.
            $table->string('device_bound_verdict', 32)->default('unknown');

            $table->string('attestation_format', 40)->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_used_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 500)->nullable();

            $table->timestamps();

            $table->index('doctor_device_id');
            $table->index('revoked_at');
            $table->index(
                ['doctor_device_id', 'revoked_at'],
                'trx_dd_webauthn_cred_device_revoked_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_webauthn_credentials');
    }
};
