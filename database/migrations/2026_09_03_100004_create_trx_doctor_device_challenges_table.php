<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — proof challenges.
 *
 * A challenge is a server-issued random nonce the device must sign with the
 * private key that never leaves its Android Keystore.
 *
 * REPLAY DEFENCE
 *  - `nonce` is unique and generated from a CSPRNG.
 *  - `expires_at` bounds the window.
 *  - `consumed_at` makes it strictly single-use; consumption happens inside the
 *    same locked transaction as verification, so two concurrent submissions of
 *    the same challenge cannot both succeed.
 *  - `doctor_device_id` binds the challenge to ONE device, so a challenge issued
 *    for device A can never be answered by device B.
 *
 * Only the nonce is stored. Signatures are verified and discarded — there is no
 * value in keeping them and every reason not to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('doctor_device_id')
                ->constrained('mst_doctor_devices')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('nonce', 128)->unique();
            $table->string('purpose', 40)->default('device_proof');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['doctor_device_id', 'consumed_at'], 'trx_dd_challenges_device_consumed_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_challenges');
    }
};
