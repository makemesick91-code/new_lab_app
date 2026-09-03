<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 —
 * mst_doctor_device_authorizations.
 *
 * Phase 2 registered PHYSICAL devices. What was missing is the sentence
 * "this doctor may use this device", and conflating the two would have forced
 * a duplicate device row per doctor — which is wrong, because one clinic
 * tablet legitimately serves several doctors.
 *
 *      Doctor ──┐
 *               ├── DoctorDeviceAuthorization ── DoctorDevice
 *      Doctor ──┘
 *
 * ONE CANONICAL ROW PER PAIR. `UNIQUE(doctor_id, doctor_device_id)` is the
 * whole idempotency guarantee for automatic request creation: a doctor who taps
 * login ten times produces one PENDING row because the database refuses the
 * rest, not because the application remembered to check. Lifecycle history is
 * preserved on the row (rejected_*, revoked_*, re_request_*) and in the
 * append-only `sys_audit_logs` trail — never by accumulating rows.
 *
 * RESTRICT on both foreign keys: an authorization is security history, so a
 * doctor or a device that still carries one cannot be deleted out from under
 * it. There is deliberately no soft delete and no destroy route.
 *
 * REJECTED IS NOT A LOOP. A rejected pair stays rejected; the next login
 * attempt reports it and creates nothing. Reopening requires a privileged
 * `allow re-request`, which records WHICH rejection it forgives in
 * `re_request_allowed_for_rejected_at`. The allowance is live only while that
 * still equals `rejected_at`, so a later rejection spends it automatically and
 * no rejection is ever erased.
 *
 * REVOKED IS TERMINAL. Withdrawn trust is not handed back; a fresh approval
 * lifecycle is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctor_device_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('doctor_id')->constrained('mst_doctors')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_device_id')->constrained('mst_doctor_devices')
                ->cascadeOnUpdate()->restrictOnDelete();

            $table->string('status', 20)->default('pending');

            // How the row came to exist. `app_login` is the automatic path.
            $table->string('request_source', 30)->default('app_login');
            $table->timestamp('requested_at')->nullable();
            // The doctor's OWN account, recorded for provenance. It is never an
            // authority: nothing a doctor sends approves their own device.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason', 500)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 500)->nullable();

            $table->timestamp('re_request_allowed_at')->nullable();
            $table->foreignId('re_request_allowed_by')->nullable()->constrained('users')->nullOnDelete();
            // WHICH rejection this allowance forgives. Copied from `rejected_at`
            // when the allowance is granted; the allowance is live only while
            // the two still match. That makes the stamp self-spending WITHOUT
            // depending on clock ordering: an allowance granted in the same
            // second as the rejection still works, and a LATER rejection
            // replaces `rejected_at` so the old allowance stops matching. A
            // `re_request_allowed_at > rejected_at` comparison looked equivalent
            // and was not — same-second timestamps made it fail closed on the
            // legitimate path.
            $table->timestamp('re_request_allowed_for_rejected_at')->nullable();

            // Set only on a login that was actually permitted under enforcement.
            $table->timestamp('last_authorized_login_at')->nullable();

            $table->timestamps();

            $table->unique(['doctor_id', 'doctor_device_id'], 'mst_dd_authorizations_pair_unique');
            $table->index('status');
            $table->index(['status', 'requested_at'], 'mst_dd_authorizations_status_requested_index');
            $table->index('doctor_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctor_device_authorizations');
    }
};
