<?php

namespace App\Modules\Prescription\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;
use App\Modules\Prescription\Gateways\WhatsAppGatewayInterface;
use App\Modules\Prescription\Models\PrescriptionWhatsAppDelivery;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Support\Facades\DB;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — hand a prescription to the
 * patient over the official WhatsApp Business Platform.
 *
 * Deliberate properties:
 *   - it only ever runs from an explicit operator action, never on save,
 *     finalize or any background schedule;
 *   - it fails closed. A missing credential, an unusable number or a provider
 *     rejection raises, and NOTHING about the prescription or the visit is
 *     mutated — a failed send can never corrupt a clinical record;
 *   - it is idempotent per (prescription, recipient, template): an accidental
 *     double submit reuses the same row and a second send is refused unless the
 *     operator explicitly asks to resend;
 *   - it records who sent what, where, and what the provider answered.
 */
class PrescriptionWhatsAppDeliveryService
{
    public function __construct(
        private readonly WhatsAppGatewayInterface $gateway,
        private readonly WhatsAppRecipientResolver $recipients,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly AuditLogService $auditLog,
    ) {}

    public function isEnabled(): bool
    {
        return $this->gateway->isEnabled();
    }

    /**
     * @throws WhatsAppDeliveryException
     */
    public function send(RmePrescription $prescription, User $actor, bool $allowResend = false): PrescriptionWhatsAppDelivery
    {
        $prescription->loadMissing(['patient', 'clinicVisit', 'branch']);

        // Branch boundary first: a prescription from another branch is never
        // sendable, no matter which URL got us here.
        if (! $this->workingBranchScope->allows($actor, (int) $prescription->branch_id)) {
            throw new WhatsAppDeliveryException(
                'Resep ini berasal dari cabang lain. Pilih cabang kerja yang sesuai terlebih dahulu.'
            );
        }

        $this->gateway->assertReadyToSend();

        $templateName = (string) config('whatsapp.prescription_template.name');
        $templateLanguage = (string) config('whatsapp.prescription_template.language', 'id');

        if ($templateName === '') {
            throw WhatsAppDeliveryException::misconfigured('nama template resep');
        }

        $recipient = $this->recipients->resolveForPatient($prescription->patient);

        $delivery = $this->claimDelivery($prescription, $recipient, $templateName, $templateLanguage, $actor, $allowResend);

        $response = $this->gateway->sendTemplate(
            $recipient,
            $templateName,
            $templateLanguage,
            $this->bodyParametersFor($prescription),
        );

        if (! $response->successful) {
            $delivery->forceFill([
                'status' => PrescriptionWhatsAppDelivery::STATUS_FAILED,
                'provider_error_code' => $response->errorCode,
                'provider_error_message' => $response->errorMessage,
            ])->save();

            $this->audit($delivery, 'SEND_PRESCRIPTION_WHATSAPP_FAILED', $actor);

            throw new WhatsAppDeliveryException(
                (string) $response->errorMessage,
                $response->errorCode,
                $response->retryable,
            );
        }

        $delivery->forceFill([
            'status' => PrescriptionWhatsAppDelivery::STATUS_SENT,
            'provider_message_id' => $response->messageId,
            'provider_error_code' => null,
            'provider_error_message' => null,
            'sent_at' => now(),
        ])->save();

        $this->audit($delivery, 'SEND_PRESCRIPTION_WHATSAPP', $actor);

        return $delivery;
    }

    /**
     * Reserve the delivery row before calling the provider, so two concurrent
     * submits contend on the unique idempotency key instead of both sending.
     */
    private function claimDelivery(
        RmePrescription $prescription,
        string $recipient,
        string $templateName,
        string $templateLanguage,
        User $actor,
        bool $allowResend,
    ): PrescriptionWhatsAppDelivery {
        return DB::transaction(function () use ($prescription, $recipient, $templateName, $templateLanguage, $actor, $allowResend) {
            $existing = PrescriptionWhatsAppDelivery::query()
                ->where('rme_prescription_id', $prescription->id)
                ->where('status', PrescriptionWhatsAppDelivery::STATUS_SENT)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $allowResend) {
                throw new WhatsAppDeliveryException(
                    'Resep ini sudah dikirim ke WhatsApp pasien pada '
                    .$existing->sent_at?->format('d/m/Y H:i')
                    .'. Centang "kirim ulang" bila memang perlu dikirim lagi.'
                );
            }

            $attempt = (int) PrescriptionWhatsAppDelivery::query()
                ->where('rme_prescription_id', $prescription->id)
                ->count();

            $key = hash('sha256', implode('|', [
                $prescription->id,
                $recipient,
                $templateName,
                $allowResend ? $attempt : 0,
            ]));

            $delivery = PrescriptionWhatsAppDelivery::query()->firstOrNew(['idempotency_key' => $key]);

            $delivery->forceFill([
                'rme_prescription_id' => $prescription->id,
                'clinic_visit_id' => $prescription->clinic_visit_id,
                'patient_id' => $prescription->patient_id,
                'branch_id' => $prescription->branch_id,
                'recipient_msisdn' => $recipient,
                'status' => PrescriptionWhatsAppDelivery::STATUS_PENDING,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'sent_by' => $actor->id,
                'attempts' => (int) $delivery->attempts + 1,
            ])->save();

            return $delivery;
        });
    }

    /**
     * Only what belongs on a prescription hand-off. No diagnosis, no clinical
     * notes, no KTP/NIK — the template carries the patient's name, the issuing
     * branch and the prescription date.
     *
     * @return array<int, string>
     */
    private function bodyParametersFor(RmePrescription $prescription): array
    {
        return [
            (string) ($prescription->patient_name_snapshot ?: $prescription->patient?->name ?: 'Pasien'),
            (string) ($prescription->branch?->name ?: config('app.name')),
            (string) ($prescription->prescription_date?->format('d/m/Y') ?: now()->format('d/m/Y')),
        ];
    }

    private function audit(PrescriptionWhatsAppDelivery $delivery, string $action, User $actor): void
    {
        $this->auditLog->log(
            'trx_rme_prescription_whatsapp_deliveries',
            (int) $delivery->id,
            $action,
            null,
            [
                'rme_prescription_id' => $delivery->rme_prescription_id,
                'clinic_visit_id' => $delivery->clinic_visit_id,
                'branch_id' => $delivery->branch_id,
                'status' => $delivery->status,
                // Masked: the audit trail proves where it went without storing
                // the patient's full number a second time.
                'recipient' => $delivery->maskedRecipient(),
                'provider_message_id' => $delivery->provider_message_id,
                'provider_error_code' => $delivery->provider_error_code,
            ],
            $actor,
        );
    }
}
