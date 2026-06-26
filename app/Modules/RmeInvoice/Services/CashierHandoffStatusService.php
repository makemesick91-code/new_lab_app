<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;

/**
 * Hotfix Sprint 60.7 — Doctor → Cashier handoff status.
 *
 * Derives a single, cashier-readable "handoff status" for a clinic visit from the
 * existing visit status, medical record finalization, consent verification, and
 * active invoice/payment state. This is read-only/visibility logic: it never
 * mutates state and never changes payment, consent, or RME workflow rules. It only
 * classifies what the front office/cashier is currently waiting on so the
 * doctor → cashier handoff is visible without manual WhatsApp confirmation.
 */
class CashierHandoffStatusService
{
    public const WAITING_DOCTOR = 'waiting_doctor';

    public const PENDING_RME = 'pending_rme';

    public const CONSENT_PENDING = 'consent_pending';

    public const READY_TO_PAY = 'ready_to_pay';

    public const IN_PAYMENT = 'in_payment';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /**
     * Handoff groups shown on the cashier sync queue, in pipeline order. Terminal
     * states (completed/cancelled) are intentionally excluded from the queue.
     *
     * @var array<int, string>
     */
    public const QUEUE_GROUPS = [
        self::WAITING_DOCTOR,
        self::PENDING_RME,
        self::CONSENT_PENDING,
        self::READY_TO_PAY,
        self::IN_PAYMENT,
    ];

    /**
     * @var array<string, array{label: string, tone: string, hint: string}>
     */
    public const META = [
        self::WAITING_DOCTOR => [
            'label' => 'Menunggu Input Dokter',
            'tone' => 'neutral',
            'hint' => 'Pasien terdaftar, dokter belum mulai pemeriksaan.',
        ],
        self::PENDING_RME => [
            'label' => 'Menunggu Finalisasi RME / Tindakan',
            'tone' => 'info',
            'hint' => 'Dokter sedang mengisi rekam medis / tindakan, belum difinalisasi.',
        ],
        self::CONSENT_PENDING => [
            'label' => 'Consent Belum Lengkap',
            'tone' => 'warning',
            'hint' => 'Surat persetujuan tindakan belum dikonfirmasi pasien & dokter.',
        ],
        self::READY_TO_PAY => [
            'label' => 'Siap Bayar',
            'tone' => 'success',
            'hint' => 'RME final & consent lengkap. Siap dibuatkan tagihan / dibayar.',
        ],
        self::IN_PAYMENT => [
            'label' => 'Dalam Proses Pembayaran',
            'tone' => 'info',
            'hint' => 'Tagihan sudah dibuat dengan pembayaran sebagian (partial).',
        ],
        self::COMPLETED => [
            'label' => 'Selesai',
            'tone' => 'success',
            'hint' => 'Kunjungan selesai & lunas.',
        ],
        self::CANCELLED => [
            'label' => 'Dibatalkan',
            'tone' => 'danger',
            'hint' => 'Kunjungan dibatalkan.',
        ],
    ];

    /**
     * Determine the handoff status key for a visit using only existing data.
     */
    public function determineKey(ClinicVisit $visit): string
    {
        if ($visit->status === ClinicVisit::STATUS_CANCELLED) {
            return self::CANCELLED;
        }

        if ($visit->status === ClinicVisit::STATUS_COMPLETED) {
            return self::COMPLETED;
        }

        if ($visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
            $record = $visit->medicalRecord;

            // Defensive: a cashier_pending visit should already have a finalized RME,
            // but if a draft slips through it is still a doctor-side completion wait.
            if ($record !== null && $record->status !== MedicalRecord::STATUS_FINAL) {
                return self::PENDING_RME;
            }

            $invoice = $visit->rmeInvoice;
            if ($invoice !== null && $invoice->status === RmeInvoice::STATUS_PARTIAL) {
                return self::IN_PAYMENT;
            }

            if (! $visit->hasVerifiedConsent()) {
                return self::CONSENT_PENDING;
            }

            return self::READY_TO_PAY;
        }

        if ($visit->status === ClinicVisit::STATUS_IN_PROGRESS) {
            return self::PENDING_RME;
        }

        // registered / waiting
        return self::WAITING_DOCTOR;
    }

    /**
     * @return array{key: string, label: string, tone: string, hint: string}
     */
    public function determine(ClinicVisit $visit): array
    {
        $key = $this->determineKey($visit);
        $meta = self::META[$key];

        return [
            'key' => $key,
            'label' => $meta['label'],
            'tone' => $meta['tone'],
            'hint' => $meta['hint'],
        ];
    }

    /** @return array{label: string, tone: string, hint: string} */
    public function meta(string $key): array
    {
        return self::META[$key] ?? self::META[self::WAITING_DOCTOR];
    }
}
