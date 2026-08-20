<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01.
 *
 * The single place a PERSETUJUAN TINDAKAN MEDIS is created, and the single
 * source of truth for whether a visit has one.
 *
 * The rule this service exists to enforce: a consent is evidence that a person
 * signed something, so it may only be written from an actual signature, never
 * from a caller asserting that a signature happened. Before this sprint the
 * payment request supplied two booleans and the payment service wrote them to
 * the visit and then asserted against what it had just written — the gate
 * authored its own evidence. Nothing here accepts a "signed = true" input.
 */
class RmeVisitConsentService
{
    public function __construct(
        private readonly RmeVisitConsentRepositoryInterface $consents,
        private readonly RmeVisitConsentNumberGeneratorService $numbers,
        private readonly PrescriptionCanvasDecoder $canvas,
        private readonly ClinicalClock $clock,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * The one predicate the payment gate depends on.
     */
    public function hasValidConsent(ClinicVisit $visit): bool
    {
        return $this->validConsentFor($visit) !== null;
    }

    public function validConsentFor(ClinicVisit $visit): ?RmeVisitConsent
    {
        return $this->consents->validForVisit($visit->id);
    }

    /**
     * @return Collection<int, RmeVisitConsent>
     */
    public function historyFor(ClinicVisit $visit): Collection
    {
        return $this->consents->historyForVisit($visit->id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function availableTemplates(): array
    {
        return (array) config('rme_consent.templates', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function template(string $code): array
    {
        $template = config('rme_consent.templates.'.$code);

        if (! is_array($template)) {
            throw ValidationException::withMessages([
                'template_code' => 'Form persetujuan tidak dikenal.',
            ]);
        }

        return $template;
    }

    /*
    |--------------------------------------------------------------------------
    | Timing gate
    |--------------------------------------------------------------------------
    */

    /**
     * Consent belongs to the moment AFTER the doctor has finished examining and
     * BEFORE the cashier takes payment, which is exactly the cashier_pending
     * state. Signing earlier would be consent to a treatment that has not been
     * decided; signing on a terminal visit would be back-dating evidence.
     *
     * Fails closed: anything that is not cashier_pending is refused.
     */
    public function isSignable(ClinicVisit $visit): bool
    {
        return $visit->status === ClinicVisit::STATUS_CASHIER_PENDING;
    }

    public function assertSignable(ClinicVisit $visit): void
    {
        if ($this->isSignable($visit)) {
            return;
        }

        throw ValidationException::withMessages([
            'clinic_visit_id' => 'Persetujuan tindakan medis baru dapat ditandatangani setelah dokter menyelesaikan pemeriksaan.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    /**
     * Record a signed consent for a visit.
     *
     * @param  array<string, mixed>  $data
     */
    public function sign(ClinicVisit $visit, User $operator, array $data): RmeVisitConsent
    {
        $this->assertSignable($visit);

        $template = $this->template((string) ($data['template_code'] ?? ''));

        // Clause 8 must be an explicit choice. A missing value is not "no", and
        // it is certainly not "yes" — it is an incomplete consent.
        if (! array_key_exists('documentation_consent', $data) || $data['documentation_consent'] === null || $data['documentation_consent'] === '') {
            throw ValidationException::withMessages([
                'documentation_consent' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            ]);
        }

        $documentationConsent = filter_var($data['documentation_consent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($documentationConsent === null) {
            throw ValidationException::withMessages([
                'documentation_consent' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            ]);
        }

        // The signature is the evidence. No signature, no consent.
        $consenterSignature = $this->canvas->decode(
            $data['consenter_signature'] ?? null,
            'consenter_signature',
        );

        if ($consenterSignature === null) {
            throw ValidationException::withMessages([
                'consenter_signature' => 'Tanda tangan pemberi persetujuan wajib diisi.',
            ]);
        }

        // The doctor's signature area is supported but optional: by this point
        // the doctor has finished the examination and may not be at the cashier.
        // The doctor's IDENTITY, however, is always recorded — see below.
        $doctorSignature = $this->canvas->decode(
            $data['doctor_signature'] ?? null,
            'doctor_signature',
        );

        $visit->loadMissing(['patient', 'doctor', 'branch']);
        $patient = $visit->patient;

        if ($patient === null) {
            throw ValidationException::withMessages([
                'clinic_visit_id' => 'Kunjungan ini tidak memiliki data pasien.',
            ]);
        }

        $relationship = (string) ($data['consenter_relationship'] ?? '');
        $isSelf = $relationship === 'self';

        return DB::transaction(function () use (
            $visit,
            $operator,
            $data,
            $template,
            $documentationConsent,
            $consenterSignature,
            $doctorSignature,
            $patient,
            $relationship,
            $isSelf,
        ): RmeVisitConsent {
            $consentNumber = $this->numbers->generate();
            $directory = trim((string) config('rme_consent.signature_directory', 'rme-consents'), '/');
            $folder = $directory.'/'.$consentNumber;

            $consenterPath = $folder.'/consenter-'.Str::random(12).'.png';
            $this->disk()->put($consenterPath, $consenterSignature);

            $doctorPath = null;
            if ($doctorSignature !== null) {
                $doctorPath = $folder.'/doctor-'.Str::random(12).'.png';
                $this->disk()->put($doctorPath, $doctorSignature);
            }

            // The treating doctor comes from the visit, never from the request.
            // Whoever operates the tablet cannot nominate a different doctor.
            $doctor = $visit->doctor;

            return $this->consents->create([
                'consent_number' => $consentNumber,
                'branch_id' => $visit->branch_id,
                'clinic_visit_id' => $visit->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor?->id,

                'template_code' => $template['code'],
                'template_version' => $template['version'],
                'content_snapshot' => $this->snapshot($template),

                'consenter_relationship' => $relationship,
                'consenter_name' => $isSelf
                    ? $patient->name
                    : (string) ($data['consenter_name'] ?? ''),
                'consenter_age' => $isSelf
                    ? (string) ($patient->age() ?? '')
                    : (string) ($data['consenter_age'] ?? ''),
                'consenter_gender' => $isSelf
                    ? $patient->gender
                    : ($data['consenter_gender'] ?? null),
                'consenter_address' => $isSelf
                    ? $patient->address
                    : ($data['consenter_address'] ?? null),
                'consenter_identity_number' => $isSelf
                    ? $patient->ktp_number
                    : ($data['consenter_identity_number'] ?? null),

                'patient_name_snapshot' => $patient->name,
                'patient_age_snapshot' => (string) ($patient->age() ?? ''),
                'patient_gender_snapshot' => $patient->gender,
                'patient_address_snapshot' => $patient->address,
                'patient_identity_number_snapshot' => $patient->ktp_number,
                'medical_record_number_snapshot' => $patient->medical_record_number,

                'medical_action' => (string) ($data['medical_action'] ?? ''),
                'treatment_summary' => $data['treatment_summary'] ?? null,

                'documentation_consent' => $documentationConsent,

                'consenter_signature_path' => $consenterPath,
                'doctor_signature_path' => $doctorPath,
                'doctor_name_snapshot' => $doctor?->name,
                'signed_location' => $template['location'] ?? null,
                'signed_at' => $this->clock->now(),
                'signed_by' => $operator->id,
            ]);
        });
    }

    /**
     * Correction path. A signed consent is never edited or deleted; it is
     * voided, with a reason, and a fresh one is signed in its place.
     */
    public function void(RmeVisitConsent $consent, User $operator, string $reason): RmeVisitConsent
    {
        if ($consent->isVoided()) {
            throw ValidationException::withMessages([
                'consent' => 'Persetujuan ini sudah dibatalkan.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($consent, $operator, $reason): RmeVisitConsent {
            $consent->forceFill([
                'voided_at' => $this->clock->now(),
                'voided_by' => $operator->id,
                'void_reason' => $reason,
            ])->save();

            return $consent->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Signature files
    |--------------------------------------------------------------------------
    */

    public function diskName(): string
    {
        return (string) config('rme_consent.signature_disk', 'local');
    }

    public function disk()
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Read a stored signature back as a data URI so a print/PDF template can
     * embed it without the file ever gaining a public URL.
     */
    public function signatureDataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $this->disk()->get($path));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Freeze the wording that was actually agreed to. Editing the template
     * afterwards must never change what a patient already signed.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function snapshot(array $template): array
    {
        return [
            'code' => $template['code'],
            'version' => $template['version'],
            'title' => $template['title'],
            'consent_statement' => $template['consent_statement'] ?? null,
            'clauses_intro' => $template['clauses_intro'] ?? null,
            'clauses' => $template['clauses'] ?? [],
            'documentation_clause' => $template['documentation_clause'] ?? null,
            'declaration' => $template['declaration'] ?? null,
            'signature_labels' => $template['signature_labels'] ?? [],
            'location' => $template['location'] ?? null,
        ];
    }
}
