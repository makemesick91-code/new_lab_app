<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 —
 * trx_doctor_device_login_challenges.
 *
 * WHY A SECOND CHALLENGE TABLE RATHER THAN REUSING THE PHASE 3 ONE
 *
 * `trx_doctor_device_challenges.doctor_device_id` is NOT NULL, and its comment
 * says exactly why: "binds the challenge to ONE device, so a challenge issued
 * for device A can never be answered by device B". A doctor's very first login
 * happens before any device row exists, so reusing that table would have meant
 * relaxing that column to nullable — weakening a live Phase 3 invariant for the
 * convenience of not adding a table. This table exists instead, and the Phase 3
 * binding is left exactly as strict as it was.
 *
 * The binding here is the PUBLIC KEY FINGERPRINT, which is what the signed
 * message commits to anyway (see DeviceProofMessage). A challenge issued for
 * fingerprint A therefore cannot be answered with a signature over
 * fingerprint B — the signed bytes would differ.
 *
 * `doctor_device_id` is present but nullable, for provenance once the device is
 * known. It is never the thing the signature is checked against.
 *
 * Same replay defence as Phase 3: CSPRNG nonce, unique, time-bounded, and
 * single-use via `consumed_at` claimed under a row lock in its own committed
 * transaction — so a failed verification can never hand the attempt back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_login_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // The trust anchor: the fingerprint the signature must commit to.
            $table->string('public_key_fingerprint', 128);

            // Provenance only, once a device row exists. Never an authority.
            $table->foreignId('doctor_device_id')->nullable()
                ->constrained('mst_doctor_devices')->cascadeOnUpdate()->nullOnDelete();

            $table->string('nonce', 128)->unique();
            $table->string('purpose', 40)->default('doctor_login');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['public_key_fingerprint', 'consumed_at'], 'trx_dd_login_challenges_fp_consumed_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_login_challenges');
    }
};
