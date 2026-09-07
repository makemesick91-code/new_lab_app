<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — one-time, expiring, ceremony-bound challenges.
 *
 * WHY NOT REUSE `trx_doctor_device_challenges`
 *
 * That table was built for the Android proof, and its shape encodes an
 * assumption this ceremony breaks: `doctor_device_id` is NOT NULL, because the
 * Clinic App always knows which device it is before it asks for a nonce.
 *
 * A WebAuthn ASSERTION does not. At the moment the challenge is issued we know
 * only which USER typed a password; which device answers is precisely what the
 * assertion is about to prove. Forcing a device id at issue time would mean
 * either inventing one or letting the CLIENT nominate the device it is about to
 * be checked against — which would hand the attacker the answer to the
 * question.
 *
 * So the device is nullable and is filled in only for REGISTRATION, where the
 * pending device genuinely exists first.
 *
 * WHAT MAKES A CHALLENGE SAFE
 *
 *  - `challenge` is unique, so the same random value can never be live twice.
 *  - `consumed_at` is claimed in its own committed transaction before the
 *    signature is verified, so a FAILED verification still burns the nonce.
 *    Burning it inside the verification transaction was a real vulnerability in
 *    the Android path: a denial rolled the burn back and allowed unlimited
 *    retries against one fixed nonce.
 *  - `ceremony` is stored and checked, so a registration challenge cannot be
 *    replayed into an assertion.
 *  - `session_id_hash` binds the challenge to the browser session that asked
 *    for it, so a challenge issued to one visitor cannot be completed by
 *    another.
 *  - `user_id` binds an assertion challenge to the account that passed the
 *    password step, so a challenge cannot be carried across users.
 *
 * ADDITIVE. Creates one table and alters nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_webauthn_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // base64url of 32 CSPRNG bytes.
            $table->string('challenge', 255)->unique();

            // 'registration' | 'assertion'
            $table->string('ceremony', 20);

            /*
             * Assertion: the account that already passed the password step.
             * Registration: the operator running the enrolment.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Registration only. Null for an assertion, deliberately — see the
             * class comment.
             */
            $table->foreignId('doctor_device_id')
                ->nullable()
                ->constrained('mst_doctor_devices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * SHA-256 of the session id. Hashed rather than stored raw so that
             * read access to this table does not yield a set of live session
             * identifiers.
             */
            $table->string('session_id_hash', 64)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['ceremony', 'consumed_at'], 'trx_dd_webauthn_chal_ceremony_index');
            $table->index('expires_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_webauthn_challenges');
    }
};
