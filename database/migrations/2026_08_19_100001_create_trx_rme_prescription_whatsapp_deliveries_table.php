<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — delivery record for a
 * prescription sent to a patient over the WhatsApp Business Platform.
 *
 * Additive only. It records who sent what, where it went and what the provider
 * answered, and its unique idempotency key is what stops an accidental double
 * send from reaching the patient twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_prescription_whatsapp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_prescription_id')->constrained('trx_rme_prescriptions')->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();

            // Normalised E.164 digits actually dialled, so an operator can see
            // where a message went. Never a KTP/NIK.
            $table->string('recipient_msisdn', 20);

            $table->string('status', 16)->default('pending');
            $table->string('template_name', 120)->nullable();
            $table->string('template_language', 16)->nullable();

            $table->string('provider_message_id', 191)->nullable();
            $table->string('provider_error_code', 32)->nullable();
            $table->text('provider_error_message')->nullable();

            // Guards against a double-submit reaching the provider twice.
            $table->string('idempotency_key', 191)->unique();

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['rme_prescription_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_prescription_whatsapp_deliveries');
    }
};
