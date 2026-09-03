<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — enrolment sessions.
 *
 * One row per pairing attempt by an Android install.
 *
 * SECURITY SHAPE
 *  - `pairing_code_hash` only. The human-readable pairing code is returned to
 *    the requesting device ONCE and never persisted in the clear, so a database
 *    reader cannot replay an enrolment.
 *  - `expires_at` + `consumed_at` make a session short-lived and single-use.
 *  - `public_key` is captured at REQUEST time and frozen. Approval binds that
 *    exact key, so an attacker cannot swap the key between request and approval.
 *  - The claimed device metadata (platform/model/os/app) is ADVISORY only. It is
 *    shown to the approving admin as context and is never an authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Bound when an administrator approves the pairing.
            $table->foreignId('doctor_device_id')->nullable()
                ->constrained('mst_doctor_devices')->cascadeOnUpdate()->restrictOnDelete();

            // Hash only — the plaintext pairing code is shown once, to the device.
            $table->string('pairing_code_hash', 128)->unique();

            $table->text('public_key');
            $table->string('public_key_fingerprint', 128);
            $table->string('key_algorithm', 40);

            // Advisory context for the approving admin. Never an authority.
            $table->string('platform', 40)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('os_version', 60)->nullable();
            $table->string('app_version', 60)->nullable();

            $table->string('status', 20)->default('pending');

            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('public_key_fingerprint');
            $table->index(['status', 'expires_at'], 'trx_dd_enrollments_status_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_enrollments');
    }
};
