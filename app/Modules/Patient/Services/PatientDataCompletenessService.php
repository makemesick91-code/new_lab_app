<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Sprint 61.0 — Patient Data Completeness Audit.
 *
 * Deterministic, read-only scoring of patient record quality. Never mutates a
 * patient. Scoring is transparent: every tracked field is worth an equal slice
 * of 100%. Critical fields drive the "lengkap / tidak lengkap" classification;
 * optional fields only affect the numeric score.
 *
 * Privacy: KTP is never returned in full. {@see maskKtp()} exposes only the last
 * four digits behind a fixed "****" prefix so record length is not leaked.
 *
 * Note: marital status (status pernikahan) and religion (agama) are intentionally
 * NOT audited — those columns do not exist on `mst_patients` and Sprint 61.0 is
 * an audit-only sprint (no schema change). They can be added when the columns do.
 */
class PatientDataCompletenessService
{
    /**
     * Critical operational fields. A patient is "lengkap" only when every one of
     * these is present. `branch` checks branch_id, `contact` is satisfied by
     * either phone OR whatsapp_number.
     *
     * @var array<string, string>
     */
    public const CRITICAL_FIELDS = [
        'branch' => 'Cabang',
        'medical_record_number' => 'No. RM',
        'name' => 'Nama',
        'gender' => 'Jenis Kelamin',
        'date_of_birth' => 'Tanggal Lahir',
        'contact' => 'No. HP / WA',
        'address' => 'Alamat',
    ];

    /**
     * Optional fields tracked for the completeness score only. KTP is tracked by
     * availability — its value is never scored or displayed in full.
     *
     * @var array<string, string>
     */
    public const OPTIONAL_FIELDS = [
        'occupation' => 'Pekerjaan',
        'email' => 'Email',
        'ktp_number' => 'KTP',
    ];

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_MISSING_CRITICAL = 'missing_critical';

    /**
     * Evaluate a single patient. Returns a deterministic, self-describing record
     * of the completeness state — safe to render directly (no KTP value).
     *
     * @return array{score:int, status:string, critical_complete:bool, complete:bool, missing_critical:array<string,string>, missing_optional:array<string,string>, missing_fields:array<string,string>}
     */
    public function evaluate(Patient $patient): array
    {
        $missingCritical = [];
        foreach (self::CRITICAL_FIELDS as $key => $label) {
            if (! $this->hasCriticalField($patient, $key)) {
                $missingCritical[$key] = $label;
            }
        }

        $missingOptional = [];
        foreach (self::OPTIONAL_FIELDS as $key => $label) {
            if (! $this->present($patient->getAttribute($key))) {
                $missingOptional[$key] = $label;
            }
        }

        $totalFields = count(self::CRITICAL_FIELDS) + count(self::OPTIONAL_FIELDS);
        $presentCount = $totalFields - count($missingCritical) - count($missingOptional);
        $score = (int) round($presentCount / $totalFields * 100);

        $criticalComplete = $missingCritical === [];

        if (! $criticalComplete) {
            $status = self::STATUS_MISSING_CRITICAL;
        } elseif ($missingOptional === []) {
            $status = self::STATUS_COMPLETE;
        } else {
            $status = self::STATUS_INCOMPLETE;
        }

        return [
            'score' => $score,
            'status' => $status,
            'critical_complete' => $criticalComplete,
            'complete' => $status === self::STATUS_COMPLETE,
            'missing_critical' => $missingCritical,
            'missing_optional' => $missingOptional,
            'missing_fields' => $missingCritical + $missingOptional,
        ];
    }

    /**
     * Mask a KTP number to "****" + last four digits. Returns null when empty so
     * callers can render an "—" / "belum diisi" marker. Never returns the full
     * value, and never leaks the record length.
     */
    public function maskKtp(?string $ktp): ?string
    {
        $ktp = trim((string) $ktp);

        if ($ktp === '') {
            return null;
        }

        if (mb_strlen($ktp) <= 4) {
            return str_repeat('*', mb_strlen($ktp));
        }

        return '****'.mb_substr($ktp, -4);
    }

    /**
     * Detect lightweight duplicate-risk groups across the supplied patient set.
     * Read-only — flags potential duplicates, never merges. Returns a map of
     * patient id => list of human-readable reasons.
     *
     * Signals (all computed within the supplied scope):
     *   - Same normalized phone / WhatsApp number
     *   - Same normalized name + birth date
     *   - Same KTP number
     *   - Same No. RM within the same branch (treated as high-risk)
     *
     * @param  Collection<int, Patient>  $patients
     * @return array<int, list<string>>
     */
    public function detectDuplicateRisks(Collection $patients): array
    {
        $phoneGroups = [];
        $nameDobGroups = [];
        $ktpGroups = [];
        $rmBranchGroups = [];

        foreach ($patients as $patient) {
            foreach ($this->contactKeys($patient) as $phone) {
                $phoneGroups[$phone][] = $patient->id;
            }

            if ($this->present($patient->name) && $patient->date_of_birth !== null) {
                $key = $this->normalizeName($patient->name).'|'.$patient->date_of_birth->format('Y-m-d');
                $nameDobGroups[$key][] = $patient->id;
            }

            if ($this->present($patient->ktp_number)) {
                $ktpGroups[trim((string) $patient->ktp_number)][] = $patient->id;
            }

            if ($this->present($patient->medical_record_number) && $patient->branch_id !== null) {
                $rmBranchGroups[$patient->branch_id.'|'.trim((string) $patient->medical_record_number)][] = $patient->id;
            }
        }

        $risks = [];
        $this->flag($risks, $phoneGroups, 'No. HP/WA sama');
        $this->flag($risks, $nameDobGroups, 'Nama + tanggal lahir sama');
        $this->flag($risks, $ktpGroups, 'KTP sama');
        $this->flag($risks, $rmBranchGroups, 'No. RM sama dalam satu cabang');

        return $risks;
    }

    /**
     * Whether the given evaluation matches a completeness status filter value.
     */
    public function matchesStatus(array $evaluation, ?string $status): bool
    {
        if ($status === null || $status === '') {
            return true;
        }

        return $evaluation['status'] === $status;
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return [
            self::STATUS_COMPLETE => 'Lengkap',
            self::STATUS_INCOMPLETE => 'Belum lengkap (opsional kurang)',
            self::STATUS_MISSING_CRITICAL => 'Kekurangan data kritis',
        ];
    }

    /**
     * All filterable missing-field types (critical + optional), keyed by field.
     *
     * @return array<string, string>
     */
    public function missingFieldOptions(): array
    {
        return self::CRITICAL_FIELDS + self::OPTIONAL_FIELDS;
    }

    private function hasCriticalField(Patient $patient, string $key): bool
    {
        return match ($key) {
            'branch' => $patient->branch_id !== null,
            'contact' => $this->present($patient->phone) || $this->present($patient->whatsapp_number),
            'date_of_birth' => $patient->date_of_birth !== null,
            default => $this->present($patient->getAttribute($key)),
        };
    }

    /**
     * @return list<string>
     */
    private function contactKeys(Patient $patient): array
    {
        $keys = [];

        foreach ([$patient->phone, $patient->whatsapp_number] as $raw) {
            $normalized = $this->normalizePhone($raw);

            if ($normalized !== '') {
                $keys[$normalized] = true;
            }
        }

        return array_keys($keys);
    }

    private function normalizePhone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function present(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param  array<int, list<string>>  $risks
     * @param  array<string, list<int>>  $groups
     */
    private function flag(array &$risks, array $groups, string $reason): void
    {
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            foreach (array_unique($ids) as $id) {
                $risks[$id][] = $reason;
            }
        }
    }
}
