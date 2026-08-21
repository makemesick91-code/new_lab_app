<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
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
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — the consent window.
     *
     * Consent used to be signable ONLY at cashier_pending, because consent was
     * the payment gate: the patient signed on the way to the counter, after the
     * treatment had already been carried out and recorded. That is the wrong
     * moment. Consent to a treatment has to be given BEFORE the treatment, while
     * refusing it can still change what happens.
     *
     * Consent now belongs to the live encounter. The doctor clicks "Mulai
     * Pemeriksaan", the visit becomes in_progress, and the consent form is taken
     * before any of this visit's RME is written.
     *
     * The window is every NON-TERMINAL visit rather than in_progress alone, and
     * that breadth is deliberate — it is what makes the gate unbypassable:
     *
     *   - registered / waiting: a visit that already has a room can reach the RME
     *     screens, so if the window started at in_progress a doctor could author
     *     a complete record before the examination formally started.
     *   - cashier_pending: "Selesai Pemeriksaan" needs no consent, so if
     *     cashier_pending were outside the window a doctor could finish the
     *     examination first and then author the entire record unconsented.
     *
     * Because the gate and the signable window are the SAME window, a blocked
     * write always has an unblocking action available — the gate can never
     * deadlock a live visit.
     *
     * Terminal visits (completed / cancelled) are outside the window and are
     * therefore never gated. That is required, not an oversight: Sprint 59 makes
     * finalized and historical records revisable, and no visit that predates this
     * consent architecture has a signed consent, so gating terminal visits would
     * permanently lock every historical record against clinical correction.
     */
    public function isWithinConsentWindow(ClinicVisit $visit): bool
    {
        return ! $visit->isTerminal();
    }

    public function isSignable(ClinicVisit $visit): bool
    {
        return $this->isWithinConsentWindow($visit);
    }

    public function assertSignable(ClinicVisit $visit): void
    {
        if ($this->isSignable($visit)) {
            return;
        }

        throw ValidationException::withMessages([
            'clinic_visit_id' => 'Persetujuan tindakan medis tidak dapat ditandatangani untuk kunjungan yang sudah selesai atau dibatalkan.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RME authoring gate
    |--------------------------------------------------------------------------
    */

    /**
     * Must this visit have a signed consent before its RME may be written?
     *
     * READS ARE NEVER GATED. A doctor may always open the patient's history, the
     * published legacy archive, previous odontograms and this visit's own record;
     * withholding the clinical history from the person deciding the treatment
     * would be unsafe, and reading harms nobody. Only WRITES to this visit's
     * record are held back.
     *
     * The ACTIVE ODONTOGRAM is deliberately NOT gated by consent. Its workflow is
     * unchanged by this sprint.
     */
    public function requiresConsentBeforeRmeAuthoring(ClinicVisit $visit): bool
    {
        return $this->isWithinConsentWindow($visit) && ! $this->hasValidConsent($visit);
    }

    /**
     * The single assertion every RME authoring path funnels through.
     *
     * It answers the question by looking up a signed document in the database. It
     * never reads a flag off the request, so no payload can assert its way past
     * it, and it is enforced in the service layer so no route, console command or
     * future controller can reach a write without passing it.
     */
    public function assertRmeAuthoringAllowed(ClinicVisit $visit): void
    {
        if (! $this->requiresConsentBeforeRmeAuthoring($visit)) {
            return;
        }

        throw ValidationException::withMessages([
            'consent' => 'Rekam medis kunjungan ini belum dapat ditulis karena Persetujuan Tindakan Medis belum ditandatangani.',
        ]);
    }

    /**
     * THE authoritative gate: may this PATIENT's record be written right now?
     *
     * Closes a bypass found by adversarial review. The per-visit assertions below
     * decide "is THIS visit consented", but which visit an authoring request is
     * "for" was taken from client input — the {clinicVisit} route parameter and an
     * optional source_visit_id field. Combined with Sprint 64.0.2, which stores
     * every new handwriting page on the patient's CANONICAL medical record (for a
     * returning patient that is their FIRST, already-terminal, therefore EXEMPT
     * visit), a doctor could open the patient's book from the Rekam Medis list —
     * ordinary navigation, no crafting — and write today's clinical note with no
     * consent anywhere, because every visit the request named was exempt.
     *
     * So the gate stops asking "which visit did you say this was for?" and asks a
     * question the request cannot influence: does this PATIENT have an open
     * encounter that has not been consented? One patient has ONE record book
     * (Sprint 64.0), so a write into that book during a live encounter is a write
     * for that encounter no matter which visit id the URL carries.
     *
     * Scope is the active RME branch set, so an unrelated visit at a branch outside
     * the RME estate cannot lock a patient's record.
     *
     * Historical correction is still possible: a patient with no open visit has
     * nothing to consent to, so Sprint 59 revision of finalized and old records is
     * unaffected. What is refused is writing into the book of a patient who is
     * mid-encounter without consent for that encounter.
     */
    public function assertPatientRecordWritable(?int $patientId): void
    {
        if ($patientId === null) {
            return;
        }

        $openVisit = ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->whereNotIn('status', [ClinicVisit::STATUS_COMPLETED, ClinicVisit::STATUS_CANCELLED])
            ->whereIn('branch_id', app(BranchService::class)->rmeEnabledIds())
            ->orderBy('id')
            ->get()
            ->first(fn (ClinicVisit $visit) => ! $this->hasValidConsent($visit));

        if ($openVisit === null) {
            return;
        }

        throw ValidationException::withMessages([
            'consent' => 'Rekam medis pasien ini belum dapat ditulis karena Persetujuan Tindakan Medis untuk kunjungan yang sedang berjalan belum ditandatangani.',
        ]);
    }

    /**
     * Assert the gate across every visit a single authoring action touches.
     *
     * Sprint 64.0.2 can redirect a new handwriting page onto the patient's
     * CANONICAL medical record, which usually belongs to an older, terminal
     * visit. Checking only the record's owner would therefore let content
     * authored during a live, unconsented encounter be written through an exempt
     * historical visit. Passing both the encounter and the storage target closes
     * that, and duplicates/nulls are harmless.
     *
     * @param  array<int, ClinicVisit|null>  $visits
     */
    public function assertRmeAuthoringAllowedForAll(array $visits): void
    {
        $seen = [];

        foreach ($visits as $visit) {
            if ($visit === null || in_array($visit->id, $seen, true)) {
                continue;
            }

            $seen[] = $visit->id;
            $this->assertRmeAuthoringAllowed($visit);
        }
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
