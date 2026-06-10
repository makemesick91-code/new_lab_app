<?php

namespace App\Support\PilotImport;

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PilotBackupImportService
{
    public const EFFECTIVE_DATE = '2026-06-10';

    public const PILOT_CLINIC_CODE = 'PILOT-IMPORT';

    /** @var list<string> */
    private const LAB_KEYWORDS = [
        'gigi palsu',
        'crown',
        'bridge',
        'veneer',
        'retainer',
        'night guard',
        'model',
        'lab',
    ];

    /** @var array<string, string> */
    private const GROUP_MAP = [
        'branches' => 'mst_branches',
        'doctors' => 'mst_doctors',
        'patients' => 'mst_patients',
        'treatments' => 'mst_lab_services',
        'lab_services' => 'mst_lab_services',
    ];

    public function __construct(
        private readonly PostgresCopyDumpReader $reader,
    ) {}

    /**
     * @return array{
     *     tables: array<string, list<array<string, mixed>>>,
     *     skipped_tables: array<string, int>,
     *     whitelisted_row_counts: array<string, int>
     * }
     */
    public function extract(string $filePath): array
    {
        return $this->reader->read($filePath);
    }

    public function import(
        array $extracted,
        bool $dryRun = false,
        ?string $only = null,
        ?int $limit = null,
    ): PilotBackupImportResult {
        $allowedTables = $this->resolveAllowedTables($only);
        $tables = $extracted['tables'];
        $detected = [];
        $imported = [];
        $updated = [];
        $skipped = [];
        $messages = [];

        foreach (PostgresCopyDumpReader::WHITELISTED_TABLES as $table) {
            $detected[$table] = count($tables[$table] ?? []);
        }

        $callback = function () use (
            $allowedTables,
            $tables,
            $limit,
            $dryRun,
            &$imported,
            &$updated,
            &$skipped,
            &$messages,
        ): void {
            $defaultBranch = null;
            $pilotClinic = null;
            $backupDoctorCodes = [];

            if (isset($tables['mst_doctors'])) {
                foreach ($tables['mst_doctors'] as $row) {
                    if (! empty($row['id']) && ! empty($row['code'])) {
                        $backupDoctorCodes[(string) $row['id']] = (string) $row['code'];
                    }
                }
            }

            if ($this->shouldImport('mst_branches', $allowedTables)) {
                foreach ($this->limitRows($tables['mst_branches'] ?? [], $limit) as $row) {
                    $result = $this->importBranch($row, $dryRun);
                    $this->tally($imported, $updated, $skipped, 'mst_branches', $result);
                    if ($result['message'] !== null) {
                        $messages[] = $result['message'];
                    }
                }
            }

            if ($this->shouldImport('mst_doctors', $allowedTables)
                || $this->shouldImport('mst_patients', $allowedTables)) {
                $pilotClinic = $this->resolvePilotClinic($dryRun);
            }

            if ($this->shouldImport('mst_doctors', $allowedTables)) {
                foreach ($this->limitRows($tables['mst_doctors'] ?? [], $limit) as $row) {
                    $result = $this->importDoctor($row, $pilotClinic, $dryRun);
                    $this->tally($imported, $updated, $skipped, 'mst_doctors', $result);
                    if ($result['message'] !== null) {
                        $messages[] = $result['message'];
                    }
                }
            }

            if ($this->shouldImport('mst_patients', $allowedTables)) {
                foreach ($this->limitRows($tables['mst_patients'] ?? [], $limit) as $row) {
                    $result = $this->importPatient($row, $pilotClinic, $backupDoctorCodes, $dryRun);
                    $this->tally($imported, $updated, $skipped, 'mst_patients', $result);
                    if ($result['message'] !== null) {
                        $messages[] = $result['message'];
                    }
                }
            }

            if ($this->shouldImport('mst_lab_services', $allowedTables)) {
                $defaultBranch = $this->resolveDefaultBranch($dryRun);

                foreach ($this->limitRows($tables['mst_lab_services'] ?? [], $limit) as $row) {
                    $result = $this->importLabService($row, $defaultBranch, $dryRun);
                    $this->tally($imported, $updated, $skipped, 'mst_lab_services', $result);
                    if ($result['message'] !== null) {
                        $messages[] = $result['message'];
                    }
                }
            }
        };

        if ($dryRun) {
            $callback();
        } else {
            DB::transaction($callback);
        }

        return new PilotBackupImportResult(
            dryRun: $dryRun,
            detected: $detected,
            imported: $imported,
            updated: $updated,
            skipped: $skipped,
            messages: $messages,
        );
    }

    /**
     * @return list<string>
     */
    public function resolveAllowedTables(?string $only): array
    {
        if ($only === null || trim($only) === '') {
            return PostgresCopyDumpReader::WHITELISTED_TABLES;
        }

        $tables = [];
        foreach (explode(',', $only) as $group) {
            $group = strtolower(trim($group));
            if ($group === '') {
                continue;
            }

            if (! isset(self::GROUP_MAP[$group])) {
                continue;
            }

            $tables[] = self::GROUP_MAP[$group];
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param  list<string>  $allowedTables
     */
    private function shouldImport(string $table, array $allowedTables): bool
    {
        return in_array($table, $allowedTables, true);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function limitRows(array $rows, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return $rows;
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array{action: string, message: ?string}
     */
    private function importBranch(array $row, bool $dryRun): array
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));

        if ($code === '' && $name === '') {
            return ['action' => 'skipped', 'message' => 'Skipped branch row without code/name.'];
        }

        $attributes = [
            'code' => $code !== '' ? $code : Str::upper(Str::slug($name, '_')),
            'name' => $name !== '' ? $name : $code,
            'address' => $row['address'] ?? null,
            'phone' => $row['phone'] ?? null,
            'is_active' => $this->toBool($row['is_active'] ?? true),
        ];

        $unique = $code !== '' ? ['code' => $code] : ['name' => $name];

        if ($dryRun) {
            $existing = Branch::query()->where($unique)->first();

            return [
                'action' => $existing ? 'updated' : 'imported',
                'message' => sprintf(
                    '[dry-run] %s branch %s (%s)',
                    $existing ? 'Would update' : 'Would import',
                    $attributes['code'],
                    $attributes['name']
                ),
            ];
        }

        $branch = Branch::query()->firstOrCreate($unique, $attributes);

        if (! $branch->wasRecentlyCreated) {
            $updates = [];
            foreach (['name', 'address', 'phone'] as $field) {
                if (blank($branch->{$field}) && filled($attributes[$field])) {
                    $updates[$field] = $attributes[$field];
                }
            }

            if ($updates !== []) {
                $branch->update($updates);

                return [
                    'action' => 'updated',
                    'message' => "Updated branch {$branch->code} empty fields.",
                ];
            }

            return ['action' => 'skipped', 'message' => null];
        }

        return [
            'action' => 'imported',
            'message' => "Imported branch {$branch->code}.",
        ];
    }

    /**
     * @return array{action: string, message: ?string}
     */
    private function importDoctor(array $row, ?Clinic $pilotClinic, bool $dryRun): array
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));

        if ($name === '') {
            return ['action' => 'skipped', 'message' => 'Skipped doctor row without name.'];
        }

        $attributes = [
            'clinic_id' => $pilotClinic?->id,
            'code' => $code !== '' ? $code : 'DOC-'.Str::upper(Str::random(6)),
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $row['email'] ?? null,
            'is_active' => $this->toBool($row['is_active'] ?? true),
        ];

        $unique = $code !== ''
            ? ['code' => $code]
            : ['name' => $name, 'phone' => $phone !== '' ? $phone : null];

        if ($dryRun) {
            $existing = Doctor::query()->where($unique)->first();

            return [
                'action' => $existing ? 'updated' : 'imported',
                'message' => sprintf(
                    '[dry-run] %s doctor %s (%s)',
                    $existing ? 'Would update' : 'Would import',
                    $attributes['code'],
                    $attributes['name']
                ),
            ];
        }

        $doctor = Doctor::query()->updateOrCreate($unique, $attributes);

        return [
            'action' => $doctor->wasRecentlyCreated ? 'imported' : 'updated',
            'message' => null,
        ];
    }

    /**
     * @param  array<string, string>  $backupDoctorCodes
     * @return array{action: string, message: ?string}
     */
    private function importPatient(
        array $row,
        ?Clinic $pilotClinic,
        array $backupDoctorCodes,
        bool $dryRun,
    ): array {
        $mrn = trim((string) ($row['medical_record_number'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $dob = $row['date_of_birth'] ?? null;

        if ($name === '') {
            return ['action' => 'skipped', 'message' => 'Skipped patient row without name.'];
        }

        $backupDoctorId = (string) ($row['doctor_id'] ?? '');
        $doctorCode = $backupDoctorCodes[$backupDoctorId] ?? null;
        $doctor = $doctorCode !== null
            ? Doctor::query()->where('code', $doctorCode)->first()
            : null;

        $canImport = $doctor !== null || ($dryRun && $doctorCode !== null);

        if (! $canImport) {
            return [
                'action' => 'skipped',
                'message' => "Skipped patient {$name}: doctor could not be resolved.",
            ];
        }

        $attributes = [
            'clinic_id' => $pilotClinic?->id,
            'doctor_id' => $doctor?->id,
            'medical_record_number' => $mrn !== '' ? $mrn : null,
            'name' => $name,
            'gender' => $row['gender'] ?? null,
            'date_of_birth' => $dob,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $row['address'] ?? null,
            'is_active' => $this->toBool($row['is_active'] ?? true),
        ];

        $unique = $mrn !== ''
            ? ['medical_record_number' => $mrn]
            : [
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'date_of_birth' => $dob,
            ];

        if ($dryRun) {
            $existing = Patient::query()->where($unique)->first();

            return [
                'action' => $existing ? 'updated' : 'imported',
                'message' => sprintf(
                    '[dry-run] %s patient %s',
                    $existing ? 'Would update' : 'Would import',
                    $attributes['medical_record_number'] ?? $attributes['name']
                ),
            ];
        }

        $attributes['doctor_id'] = $doctor->id;
        $patient = Patient::query()->updateOrCreate($unique, $attributes);

        return [
            'action' => $patient->wasRecentlyCreated ? 'imported' : 'updated',
            'message' => null,
        ];
    }

    /**
     * @return array{action: string, message: ?string}
     */
    private function importLabService(array $row, ?Branch $branch, bool $dryRun): array
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));

        if ($code === '' && $name === '') {
            return ['action' => 'skipped', 'message' => 'Skipped lab service without code/name.'];
        }

        if ($branch === null) {
            return ['action' => 'skipped', 'message' => 'Skipped lab service: no default branch.'];
        }

        $categoryName = trim((string) ($row['category'] ?? 'General'));
        if ($categoryName === '') {
            $categoryName = 'General';
        }

        $requiresLab = $this->requiresLab($name, $categoryName, (string) ($row['description'] ?? ''));
        $isActive = $this->toBool($row['is_active'] ?? true);
        $description = $row['description'] ?? null;
        $resolvedName = $name !== '' ? $name : $code;
        $resolvedCode = $code !== '' ? $code : Str::upper(Str::slug($name, '_'));
        $match = $this->resolveLabServiceTreatment($code, $name);

        if ($dryRun) {
            return $this->dryRunLabServiceResult($match, $resolvedName, $code);
        }

        $category = TreatmentCategory::query()->firstOrCreate(
            ['name' => $categoryName],
            [
                'code' => Str::upper(Str::slug($categoryName, '_')),
                'description' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]
        );

        $message = null;

        if ($match['treatment'] === null) {
            $treatment = Treatment::query()->create([
                'code' => $resolvedCode,
                'treatment_category_id' => $category->id,
                'name' => $resolvedName,
                'description' => $description,
                'default_duration_minutes' => null,
                'requires_doctor' => true,
                'requires_room' => false,
                'requires_lab' => $requiresLab,
                'is_active' => $isActive,
            ]);
            $wasCreated = true;
        } elseif ($match['match'] === 'code') {
            $treatment = $match['treatment'];
            $treatment->update([
                'treatment_category_id' => $category->id,
                'name' => $resolvedName,
                'description' => $description ?? $treatment->description,
                'requires_lab' => $requiresLab,
                'is_active' => $isActive,
            ]);
            $wasCreated = false;
        } else {
            $treatment = $match['treatment'];
            $updates = [
                'requires_lab' => $requiresLab,
                'is_active' => $isActive,
            ];

            if ($this->isBlank($treatment->description) && ! $this->isBlank($description)) {
                $updates['description'] = $description;
            }

            if ($treatment->treatment_category_id === null) {
                $updates['treatment_category_id'] = $category->id;
            }

            $treatment->update($updates);

            if ($code !== '' && $code !== $treatment->code) {
                $message = sprintf(
                    'Matched existing treatment by name %s; backup code %s not applied to avoid duplicate.',
                    $resolvedName,
                    $code
                );
            } else {
                $message = sprintf('Matched existing treatment by name %s.', $resolvedName);
            }

            $wasCreated = false;
        }

        $this->importOrUpdateTariff($branch, $treatment, $row, $isActive);

        return [
            'action' => $wasCreated ? 'imported' : 'updated',
            'message' => $message,
        ];
    }

    /**
     * @return array{treatment: ?Treatment, match: ?string}
     */
    private function resolveLabServiceTreatment(string $code, string $name): array
    {
        if ($code !== '') {
            $byCode = Treatment::query()->where('code', $code)->first();
            if ($byCode !== null) {
                return ['treatment' => $byCode, 'match' => 'code'];
            }
        }

        if ($name !== '') {
            $byName = Treatment::query()->where('name', $name)->first();
            if ($byName !== null) {
                return ['treatment' => $byName, 'match' => 'name'];
            }
        }

        return ['treatment' => null, 'match' => null];
    }

    /**
     * @param  array{treatment: ?Treatment, match: ?string}  $match
     * @return array{action: string, message: ?string}
     */
    private function dryRunLabServiceResult(array $match, string $resolvedName, string $code): array
    {
        if ($match['treatment'] !== null && $match['match'] === 'name') {
            $parts = [
                sprintf('[dry-run] Would match existing treatment by name %s', $resolvedName),
            ];

            if ($code !== '' && $code !== $match['treatment']->code) {
                $parts[] = sprintf(
                    'Matched existing treatment by name %s; backup code %s not applied to avoid duplicate.',
                    $resolvedName,
                    $code
                );
            }

            $parts[] = sprintf('[dry-run] Would create/update tariff for %s', $resolvedName);

            return [
                'action' => 'updated',
                'message' => implode(' ', $parts),
            ];
        }

        $exists = $match['treatment'] !== null;

        return [
            'action' => $exists ? 'updated' : 'imported',
            'message' => sprintf(
                '[dry-run] Would %s lab service %s to treatment/tariff%s',
                $exists ? 'update' : 'import',
                $code ?: $resolvedName,
                $exists ? sprintf(' and create/update tariff for %s', $resolvedName) : ''
            ),
        ];
    }

    private function importOrUpdateTariff(Branch $branch, Treatment $treatment, array $row, bool $isActive): void
    {
        $tariff = Tariff::query()->firstOrCreate(
            [
                'branch_id' => $branch->id,
                'treatment_id' => $treatment->id,
                'effective_date' => self::EFFECTIVE_DATE,
            ],
            [
                'price' => $row['price'] ?? 0,
                'is_active' => $isActive,
                'notes' => 'Imported from pilot backup mst_lab_services',
            ]
        );

        if (! $tariff->wasRecentlyCreated) {
            $tariff->update([
                'price' => $row['price'] ?? $tariff->price,
                'is_active' => $isActive,
            ]);
        }
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function resolvePilotClinic(bool $dryRun): ?Clinic
    {
        $existing = Clinic::query()->where('code', self::PILOT_CLINIC_CODE)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($dryRun) {
            return null;
        }

        return Clinic::query()->create([
            'code' => self::PILOT_CLINIC_CODE,
            'name' => 'RME Pilot Import Clinic',
            'phone' => null,
            'email' => null,
            'address' => 'Pilot import placeholder clinic',
            'city' => null,
            'province' => null,
            'postal_code' => null,
            'is_active' => true,
        ]);
    }

    private function resolveDefaultBranch(bool $dryRun): ?Branch
    {
        $branch = Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($branch !== null) {
            return $branch;
        }

        if ($dryRun) {
            return null;
        }

        return Branch::query()->first();
    }

    private function requiresLab(string $name, string $category, string $description): bool
    {
        $haystack = Str::lower($name.' '.$category.' '.$description);

        foreach (self::LAB_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = Str::lower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes', 'y'], true);
    }

    /**
     * @param  array<string, int>  $imported
     * @param  array<string, int>  $updated
     * @param  array<string, int>  $skipped
     * @param  array{action: string, message: ?string}  $result
     */
    private function tally(array &$imported, array &$updated, array &$skipped, string $table, array $result): void
    {
        match ($result['action']) {
            'imported' => $imported[$table] = ($imported[$table] ?? 0) + 1,
            'updated' => $updated[$table] = ($updated[$table] ?? 0) + 1,
            default => $skipped[$table] = ($skipped[$table] ?? 0) + 1,
        };
    }
}
