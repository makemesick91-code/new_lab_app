<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Support\PatientIdentityView;
use App\Modules\RME\Services\DoctorPatientScopeService;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 — PR C1 (rule 243 §3): which of a
 * patient's identity fields a viewer sees on a clinical screen.
 *
 * Owner decision 2026-11-17 ("memo list + RM number"; plan default 5, gender
 * only): a Doctor sees the name, RM number, place and date of birth, age,
 * occupation, planned treatment and gender. The phone, WhatsApp number,
 * address, email and NIK are hidden from every doctor screen; the doctor's
 * consent VIEW masks the identity numbers and hides the addresses, while the
 * printed consent document stays whole (a Doctor may not print it, rule 243
 * §1). Every other role sees exactly what it saw before.
 *
 * The audience is rule 138's Doctor, decided by the ONE predicate
 * DoctorPatientScopeService::shouldApplyDoctorScope() (rule 243 §1.2: never a
 * second "is this a doctor"): the Doctor role, unless the account also holds
 * Owner, Super Admin or Supervisor RME. A Doctor who also holds Front Office,
 * Admin Klinik or Kasir therefore gets the doctor view on every clinical
 * screen — the safe default — and keeps the full patient master form
 * (`settings.patients.edit`, `manage patients`), which this presenter never
 * touches. No viewer at all is the doctor audience (fail closed).
 *
 * Not behind `rme.doctor_encounter_scope`: live on merge. Pure: it reads the
 * loaded patient and the viewer's roles, and issues no query of its own.
 */
final class PatientIdentityPresenter
{
    public const AUDIENCE_DOCTOR = 'doctor';

    public const AUDIENCE_FULL = 'full';

    private const DASH = '-';

    private const GENDER_LABELS = ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'];

    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly PatientDataCompletenessService $completeness,
    ) {}

    public function audienceFor(?User $viewer): string
    {
        return $viewer === null || $this->doctorScope->shouldApplyDoctorScope($viewer)
            ? self::AUDIENCE_DOCTOR
            : self::AUDIENCE_FULL;
    }

    public function forViewer(?Patient $patient, ?User $viewer): PatientIdentityView
    {
        $audience = $this->audienceFor($viewer);
        $full = $audience === self::AUDIENCE_FULL;

        return new PatientIdentityView(
            audience: $audience,
            name: self::filled($patient?->name),
            medicalRecordNumber: self::filled($patient?->medical_record_number),
            birthPlaceDateAge: $this->birthPlaceDateAge($patient),
            occupation: self::filled($patient?->occupation),
            gender: $this->genderLabel($patient),
            phone: $full ? self::filled($patient?->phone) : null,
            whatsappNumber: $full ? self::filled($patient?->whatsapp_number) : null,
            email: $full ? self::filled($patient?->email) : null,
            address: $full ? self::filled($patient?->address) : null,
            biodataRows: $full ? $this->fullBiodataRows($patient) : $this->doctorBiodataRows($patient),
        );
    }

    /**
     * The identity block of the PTM as the viewer's consent PAGE renders it
     * (`rme.consents.show`, `rme.consents.countersign.create`): for a Doctor the
     * identity numbers masked ("****" + the last four) and the addresses hidden
     * (their rows are not rendered); for everyone else exactly the document.
     *
     * @return array{addresses_visible: bool, consenter_address: ?string, consenter_identity_number: ?string, patient_address: ?string, patient_identity_number: ?string}
     */
    public function forConsentViewer(RmeVisitConsent $consent, ?User $viewer): array
    {
        if ($this->audienceFor($viewer) === self::AUDIENCE_FULL) {
            return $this->forConsentDocument($consent);
        }

        return [
            'addresses_visible' => false,
            'consenter_address' => null,
            'consenter_identity_number' => $this->completeness->maskKtp($consent->consenter_identity_number),
            'patient_address' => null,
            'patient_identity_number' => $this->completeness->maskKtp($consent->patient_identity_number_snapshot),
        ];
    }

    /**
     * The printed consent document (`rme.consents.print`), unchanged: the one
     * surface that renders a full identity number (AIR-RMC-13).
     *
     * @return array{addresses_visible: bool, consenter_address: ?string, consenter_identity_number: ?string, patient_address: ?string, patient_identity_number: ?string}
     */
    public function forConsentDocument(RmeVisitConsent $consent): array
    {
        return [
            'addresses_visible' => true,
            'consenter_address' => $consent->consenter_address,
            'consenter_identity_number' => $consent->consenter_identity_number,
            'patient_address' => $consent->patient_address_snapshot,
            'patient_identity_number' => $consent->patient_identity_number_snapshot,
        ];
    }

    /**
     * Rule 243 §3 (memo M3.1, PR C2) — the biodata grid baked into a NEW RM
     * page 1 (the workspace canvas, hence the saved PNG and every later print):
     * the allowed fields only, for every audience (plan default 3: a Front
     * Office print of a new page 1 loses the address and phone). The Hotfix
     * 60.5 geometry (five rows, label|value|label|value) is kept, so the table
     * below the band never moves; the cells that held marital status, address,
     * religion, phone/WhatsApp and email are blank, exactly the cells
     * RmPageOneBiodataRedactor blanks on an old page 1 for a Doctor.
     *
     * @return array{left: list<array{0: string, 1: string}>, right: list<array{0: string, 1: string}>}
     */
    public function rmPageOneBiodata(?Patient $patient): array
    {
        $blank = ['', ''];

        return [
            'left' => [
                ['Nama', self::filled($patient?->name) ?? self::DASH],
                ['TTL / Umur', $this->birthPlaceDateAge($patient) ?? self::DASH],
                ['Pekerjaan', self::filled($patient?->occupation) ?? self::DASH],
                $blank,
                $blank,
            ],
            'right' => [
                ['Jenis Kelamin', $this->genderLabel($patient) ?? self::DASH],
                $blank,
                $blank,
                $blank,
                ['No. RM', self::filled($patient?->medical_record_number) ?? self::DASH],
            ],
        ];
    }

    /**
     * "TTL / Umur": the place of birth (memo M4.2 column, when recorded), the
     * date of birth and the age — "Parepare, 01-02-1990 / 36 tahun".
     */
    private function birthPlaceDateAge(?Patient $patient): ?string
    {
        $place = self::filled($patient?->place_of_birth);
        $date = null;

        if ($patient?->date_of_birth !== null) {
            $date = $patient->date_of_birth->format('d-m-Y');
            $age = $patient->age();

            if ($age !== null) {
                $date .= ' / '.$age.' tahun';
            }
        }

        $parts = array_values(array_filter([$place, $date], fn (?string $part): bool => $part !== null));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function genderLabel(?Patient $patient): ?string
    {
        $gender = self::filled($patient?->gender);

        return $gender === null ? null : (self::GENDER_LABELS[$gender] ?? $gender);
    }

    /**
     * The workspace table every non-doctor role saw before rule 243 §3, value
     * for value (marital status and religion have no column, so "-").
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    private function fullBiodataRows(?Patient $patient): array
    {
        $left = [
            ['Nama', self::filled($patient?->name) ?? self::DASH],
            ['TTL / Umur', $this->birthPlaceDateAge($patient) ?? self::DASH],
            ['Pekerjaan', self::filled($patient?->occupation) ?? self::DASH],
            ['Status Pernikahan', self::filled($patient?->getAttribute('marital_status')) ?? self::DASH],
            ['Alamat', self::filled($patient?->address) ?? self::DASH],
        ];
        $right = [
            ['Jenis Kelamin', $this->genderLabel($patient) ?? self::DASH],
            ['Agama', self::filled($patient?->getAttribute('religion')) ?? self::DASH],
            ['No. Tlp / Wa', self::filled($patient?->whatsapp_number) ?? self::filled($patient?->phone) ?? self::DASH],
            ['Email', self::filled($patient?->email) ?? self::DASH],
            ['No. RM', self::filled($patient?->medical_record_number) ?? self::DASH],
        ];

        return array_map(fn (array $l, array $r): array => [$l[0], $l[1], $r[0], $r[1]], $left, $right);
    }

    /**
     * The memo list, in the columns the full table uses for the same fields.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    private function doctorBiodataRows(?Patient $patient): array
    {
        return [
            ['Nama', self::filled($patient?->name) ?? self::DASH, 'Jenis Kelamin', $this->genderLabel($patient) ?? self::DASH],
            ['TTL / Umur', $this->birthPlaceDateAge($patient) ?? self::DASH, 'No. RM', self::filled($patient?->medical_record_number) ?? self::DASH],
            ['Pekerjaan', self::filled($patient?->occupation) ?? self::DASH, null, null],
        ];
    }

    private static function filled(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
