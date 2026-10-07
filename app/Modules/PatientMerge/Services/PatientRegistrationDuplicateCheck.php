<?php

namespace App\Modules\PatientMerge\Services;

use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Validation\Validator;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — duplicate prevention at
 * registration, the cheapest place to stop a duplicate.
 *
 * Deliberately LIGHT: it only reacts to STRONG matches (a near-identical name
 * on the same birth date, or a similar name on the same phone number), so two
 * patients who merely share a birthday — or genuine twins with different
 * names — register without friction. On a strong match the operator can use
 * the existing patient, open a duplicate review, or continue with a written
 * reason; continuing is recorded in the audit trail.
 *
 * The candidate summary is least-disclosure: inside the actor's branch scope it
 * carries masked NIK/phone; outside it, only name, Nomor RM and branch (the New
 * Visit identity-lookup minimum).
 */
class PatientRegistrationDuplicateCheck
{
    public const SESSION_KEY = 'duplicate_candidates';

    public const MIN_REASON = 10;

    public function __construct(
        private readonly PatientDuplicateDetectionService $detection,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, date_of_birth, phone, whatsapp_number
     */
    public function validate(Validator $validator, array $input, mixed $reason, string $reasonField): void
    {
        $matches = $this->detection->registrationMatches($input, auth()->user());

        if ($matches === []) {
            return;
        }

        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            session()->flash(self::SESSION_KEY, $matches);
            $validator->errors()->add(
                $reasonField,
                'Kemungkinan pasien ini sudah terdaftar ('.count($matches).' data mirip). Gunakan pasien yang ada, buka review duplikat, atau isi alasan (min. '.self::MIN_REASON.' karakter) untuk tetap mendaftarkan pasien baru.',
            );

            return;
        }

        $this->audit->log('patient_registration', null, 'PATIENT_DUPLICATE_WARNING_OVERRIDDEN', null, [
            'candidate_patient_ids' => array_column($matches, 'id'),
            'reason_length' => mb_strlen($reason),
        ]);
    }
}
