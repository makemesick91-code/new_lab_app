<?php

namespace App\Modules\PatientMerge\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PatientMergeOwnershipRepository implements PatientMergeOwnershipRepositoryInterface
{
    /** Child tables reached through a parent the canonical patient owns. */
    public const CHILD_TABLES = [
        'trx_odontograms' => ['clinic_visit_id', 'trx_clinic_visits'],
        'trx_medical_record_handwritings' => ['medical_record_id', 'trx_medical_records'],
        'trx_medical_record_handwriting_pages' => ['medical_record_id', 'trx_medical_records'],
        'trx_medical_record_diagnoses' => ['medical_record_id', 'trx_medical_records'],
    ];

    /** Parent columns whose moved ids every child row must have moved with. */
    public const PARENT_REFERENCES = [
        'clinic_visit_id' => 'trx_clinic_visits',
        'rme_invoice_id' => 'trx_rme_invoices',
        'medical_record_id' => 'trx_medical_records',
    ];

    /** A visit in any of these states is a live encounter. */
    public const ACTIVE_ENCOUNTER_STATUSES = [
        ClinicVisit::STATUS_REGISTERED,
        ClinicVisit::STATUS_WAITING,
        ClinicVisit::STATUS_IN_PROGRESS,
        ClinicVisit::STATUS_CASHIER_PENDING,
    ];

    public function lockPatients(array $patientIds): Collection
    {
        $patientIds = array_values(array_unique(array_map('intval', $patientIds)));
        sort($patientIds);

        return Patient::withTrashed()
            ->whereKey($patientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    public function countsFor(int $patientId): array
    {
        $counts = array_fill_keys(PatientOwnedRecordRegistry::CONSERVED_GROUPS, 0);

        foreach (PatientOwnedRecordRegistry::REASSIGN as $table => $spec) {
            $counts[$spec['group']] = ($counts[$spec['group']] ?? 0)
                + DB::table($table)->where($spec['column'], $patientId)->count();
        }

        // Derived, not moved directly: an odontogram belongs to a visit, so it
        // follows its visit and is counted through it.
        $counts['odontograms'] = DB::table('trx_odontograms')
            ->join('trx_clinic_visits', 'trx_clinic_visits.id', '=', 'trx_odontograms.clinic_visit_id')
            ->where('trx_clinic_visits.patient_id', $patientId)
            ->count();

        $counts['receivables'] = DB::table('trx_rme_invoices')
            ->where('patient_id', $patientId)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->count();

        return $counts;
    }

    public function idsFor(string $table, string $column, int $patientId): array
    {
        $this->assertRegistered($table, $column);

        return DB::table($table)
            ->where($column, $patientId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function reassign(string $table, string $column, array $ids, int $toPatientId): int
    {
        $this->assertRegistered($table, $column);

        if ($ids === []) {
            return 0;
        }

        $moved = 0;

        // Chunked so a patient with thousands of rows never builds one giant
        // IN list. Only the owner column changes; content is untouched.
        foreach (array_chunk($ids, 500) as $chunk) {
            $moved += DB::table($table)->whereIn('id', $chunk)->update([$column => $toPatientId]);
        }

        return $moved;
    }

    public function collidingActiveDoctorAssignments(int $sourcePatientId, int $canonicalPatientId): array
    {
        $canonicalDoctors = DB::table('trx_rme_patient_doctor_assignments')
            ->where('patient_id', $canonicalPatientId)
            ->whereNull('unassigned_at')
            ->pluck('doctor_id');

        if ($canonicalDoctors->isEmpty()) {
            return [];
        }

        return DB::table('trx_rme_patient_doctor_assignments')
            ->where('patient_id', $sourcePatientId)
            ->whereNull('unassigned_at')
            ->whereIn('doctor_id', $canonicalDoctors)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function closeDoctorAssignments(array $ids, CarbonInterface $at, string $note): array
    {
        if ($ids === []) {
            return [];
        }

        $original = DB::table('trx_rme_patient_doctor_assignments')
            ->whereIn('id', $ids)
            ->pluck('notes', 'id')
            ->mapWithKeys(fn ($notes, $id): array => [(int) $id => $notes])
            ->all();

        // The original note is KEPT (appended to, never overwritten) and also
        // returned so a reversal can restore it exactly.
        foreach ($original as $id => $notes) {
            $text = is_string($notes) && trim($notes) !== '' ? $notes."\n".$note : $note;

            DB::table('trx_rme_patient_doctor_assignments')
                ->where('id', $id)
                ->update(['unassigned_at' => $at, 'notes' => $text, 'updated_at' => $at]);
        }

        return $original;
    }

    public function reopenDoctorAssignments(array $originalNotesById): void
    {
        foreach ($originalNotesById as $id => $notes) {
            DB::table('trx_rme_patient_doctor_assignments')
                ->where('id', (int) $id)
                ->update(['unassigned_at' => null, 'notes' => $notes, 'updated_at' => now()]);
        }
    }

    public function activeEncounterCount(array $patientIds): int
    {
        return DB::table('trx_clinic_visits')
            ->whereIn('patient_id', array_map('intval', $patientIds))
            ->whereNull('deleted_at')
            ->whereIn('status', self::ACTIVE_ENCOUNTER_STATUSES)
            ->count();
    }

    public function activeSatusehatPatientIdentifiers(int $patientId): int
    {
        if (! Schema::hasTable('mst_satusehat_entity_identifiers')) {
            return 0;
        }

        return DB::table('mst_satusehat_entity_identifiers')
            ->where('local_entity_type', 'patient')
            ->where('local_entity_id', $patientId)
            ->where('status', 'active')
            ->when(Schema::hasColumn('mst_satusehat_entity_identifiers', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->count();
    }

    public function latestPublishedLegacyRmeDate(int $patientId): ?string
    {
        $value = DB::table('trx_rme_legacy_records')
            ->where('patient_id', $patientId)
            ->where('status', LegacyRmeRecord::STATUS_PUBLISHED)
            ->selectRaw('MAX(COALESCE(latest_rme_date, rme_date)) as latest')
            ->value('latest');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    public function latestPublishedLegacyOdontogramDate(int $patientId): ?string
    {
        $value = DB::table('trx_odontogram_legacy_records')
            ->where('patient_id', $patientId)
            ->where('status', LegacyOdontogramRecord::STATUS_PUBLISHED)
            ->max('odontogram_date');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    public function ktpOwner(string $ktp, array $exceptPatientIds): ?int
    {
        $id = Patient::withTrashed()
            ->where('ktp_number', $ktp)
            ->whereKeyNot(array_map('intval', $exceptPatientIds))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function unregisteredPatientForeignKeys(): array
    {
        $known = PatientOwnedRecordRegistry::knownReferences();
        $unknown = [];

        foreach ($this->patientForeignKeys() as $reference) {
            if (! in_array($reference, $known, true)) {
                $unknown[] = $reference;
            }
        }

        sort($unknown);

        return array_values(array_unique($unknown));
    }

    public function highWaterMarks(): array
    {
        $marks = [];

        foreach (array_merge(array_keys(PatientOwnedRecordRegistry::REASSIGN), array_keys(self::CHILD_TABLES)) as $table) {
            if (Schema::hasTable($table)) {
                $marks[$table] = (int) DB::table($table)->max('id');
            }
        }

        return $marks;
    }

    public function activitySince(int $patientId, CarbonInterface $since, array $movedByTable = [], array $ignoreUpdatedIds = [], array $highWater = []): array
    {
        $activity = [];

        foreach (PatientOwnedRecordRegistry::REASSIGN as $table => $spec) {
            $moved = array_map('intval', $movedByTable[$table] ?? []);
            $ignore = array_map('intval', $ignoreUpdatedIds[$table] ?? []);

            // New rows now owned by the canonical patient: which person they
            // belong to cannot be decided by a machine. "New" is decided by
            // the id high-water mark recorded inside the merge transaction —
            // exact, and immune to rows created in the same second as the
            // merge. Older manifests without one fall back to created_at.
            $created = DB::table($table)
                ->where($spec['column'], $patientId)
                ->when(
                    array_key_exists($table, $highWater),
                    fn ($q) => $q->where(fn ($w) => $w->where('id', '>', (int) $highWater[$table])->orWhere('created_at', '>', $since)),
                    fn ($q) => $q->where('created_at', '>=', $since),
                )
                ->when($moved !== [], fn ($q) => $q->whereNotIn('id', $moved))
                ->count();

            // Rows the merge moved, changed afterwards. The merge never touches
            // updated_at, so a later timestamp is post-merge activity (strictly
            // after the merge second, so the moved row's own last edit made in
            // that same second is not mistaken for it).
            $changed = 0;
            $watch = array_values(array_diff($moved, $ignore));

            foreach (array_chunk($watch, 500) as $chunk) {
                $changed += DB::table($table)
                    ->whereIn('id', $chunk)
                    ->where('updated_at', '>', $since)
                    ->count();
            }

            if ($created + $changed > 0) {
                $activity[$table] = $created + $changed;
            }
        }

        // Consistency probe: a row pointing at a visit / invoice / record the
        // merge moved, that the merge did NOT move itself, was written after
        // the merge began — e.g. a payment queued behind the merge's lock on
        // the invoice. A reversal would split it from its parent.
        foreach (PatientOwnedRecordRegistry::REASSIGN as $table => $spec) {
            $moved = array_map('intval', $movedByTable[$table] ?? []);
            $orphans = 0;

            foreach (self::PARENT_REFERENCES as $column => $parentTable) {
                $parents = array_map('intval', $movedByTable[$parentTable] ?? []);

                if ($parents === [] || $table === $parentTable || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach (array_chunk($parents, 500) as $chunk) {
                    $orphans += DB::table($table)
                        ->whereIn($column, $chunk)
                        ->when($moved !== [], fn ($q) => $q->whereNotIn('id', $moved))
                        ->count();
                }
            }

            if ($orphans > 0) {
                $activity[$table] = ($activity[$table] ?? 0) + $orphans;
            }
        }

        // Child rows carry no patient_id, so they are reached through their
        // parent: odontograms, handwriting and diagnoses written after the
        // merge onto anything the canonical patient now owns (its workspace
        // record may be one the merge moved in) are ambiguous by definition.
        foreach (self::CHILD_TABLES as $table => [$column, $parentTable]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $parentIds = DB::table($parentTable)->where('patient_id', $patientId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $count = 0;

            foreach (array_chunk($parentIds, 500) as $chunk) {
                $count += DB::table($table)
                    ->whereIn($column, $chunk)
                    ->where(function ($q) use ($table, $highWater, $since): void {
                        array_key_exists($table, $highWater)
                            ? $q->where('id', '>', (int) $highWater[$table])
                            : $q->where('created_at', '>=', $since);
                        $q->orWhere('updated_at', '>', $since);
                    })
                    ->count();
            }

            if ($count > 0) {
                $activity[$table] = $count;
            }
        }

        return $activity;
    }

    /**
     * Every "table.column" in the live schema that references mst_patients.
     *
     * @return array<int, string>
     */
    private function patientForeignKeys(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            return collect($connection->select(
                "SELECT kcu.table_name, kcu.column_name
                   FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage kcu
                     ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
                   JOIN information_schema.constraint_column_usage ccu
                     ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                  WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND ccu.table_name = 'mst_patients'
                    AND tc.table_schema = current_schema()"
            ))->map(fn ($row): string => $row->table_name.'.'.$row->column_name)->all();
        }

        $references = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            foreach (Schema::getForeignKeys($name) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'mst_patients') {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    $references[] = $name.'.'.$column;
                }
            }
        }

        return $references;
    }

    private function assertRegistered(string $table, string $column): void
    {
        if ((PatientOwnedRecordRegistry::REASSIGN[$table]['column'] ?? null) !== $column) {
            throw new InvalidArgumentException("Not a registered patient-owned reference: {$table}.{$column}");
        }
    }
}
