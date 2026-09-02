<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 2 — mst_doctor_devices.
 *
 * The clinic-device registry that a future Android Clinic App will enrol into.
 * Phase 2 is CAPABILITY ONLY: nothing in the authentication path reads this
 * table, and no doctor can be locked out because it is empty.
 *
 * Additive migration. There is deliberately NO soft-delete and no destroy
 * route: a device that has ever been trusted keeps its security history, and
 * withdrawal of trust is expressed as REVOKED, not as deletion.
 *
 * Two orthogonal axes are kept apart on purpose:
 *
 *   status         — the administrative decision (active / disabled / revoked)
 *   identity_state — whether the device ever cryptographically PROVED itself
 *
 * An admin-entered row is `unverified`. Only a real Android enrolment in
 * Phase 3, holding a hardware-backed key, may ever set
 * `cryptographically_verified`, so a manually typed row can never masquerade
 * as a proven device.
 *
 * `public_key_fingerprint` is the future trust anchor. It is nullable because
 * Phase 2 has no key material to record. MAC address and IMEI are deliberately
 * absent — neither is an authentication authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctor_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('device_name', 150);

            // Server-authoritative branch binding. RESTRICT so a branch that
            // still owns devices can never be deleted out from under them.
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('status', 20)->default('active');
            $table->string('identity_state', 40)->default('unverified');

            $table->string('platform', 40)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('os_version', 60)->nullable();
            $table->string('app_version', 60)->nullable();

            // Phase 3 anchor: the SHA-256 fingerprint of the device's public
            // key. Never the private key, never a reusable secret.
            $table->string('public_key_fingerprint', 128)->nullable()->unique();

            $table->text('notes')->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable();

            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disabled_reason', 500)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 500)->nullable();

            $table->timestamps();

            $table->index('branch_id');
            $table->index('status');
            $table->index(['branch_id', 'status'], 'mst_doctor_devices_branch_status_index');
            $table->unique(['branch_id', 'device_name'], 'mst_doctor_devices_branch_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctor_devices');
    }
};
