<?php

declare(strict_types=1);

use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1 — convert Cabang Sunu's deprecated
 * branch code to its canonical one, in the places where the value is ACTIVE
 * CANONICAL DATA and nowhere else.
 *
 * THIS IS A DRIFT REPAIR, NOT A FRESH RENAME. Production was found with Cabang
 * Sunu's `mst_branches` row ALREADY carrying `SPN4` — renamed by hand — while
 * the application still declared `SUN4`. That half-applied state is not
 * cosmetic, and it is the reason this migration exists:
 *
 *   - Patient 39's Nomor RM still reads `DG-SUN4-2026-564`. The branch code
 *     encoded in it named NO branch at all, so RM-derived branch resolution
 *     failed closed and that patient's legacy archive was unreachable.
 *
 *   - The LIVE rollout wave still listed `SUN4` in its approved branch codes
 *     and on its branch and operator rows, while the branch master answered
 *     `SPN4`. Admission compares those two spellings, so Cabang Sunu was locked
 *     out of the very wave it had been approved for.
 *
 * Both halves are repaired here, together, inside one transaction. Cabang
 * Telkomas was found in precisely this state one revision earlier; this
 * migration is the same shape, deliberately, so the two read alike.
 *
 * WHAT THIS MIGRATION IS. A narrow, enumerated, collision-checked rename of one
 * branch identity's code, plus the identifiers and live rollout state derived
 * from it. Every target is named explicitly. There is no scan of "every text
 * column", and there is deliberately no `replace(col, 'SUN4', 'SPN4')`
 * anywhere: a blind replacement would also rewrite the string inside a manual
 * RM sequence, inside an approval reference, inside a JSON payload, and inside
 * a patient's free text — changing meaning it was never asked to touch. Nomor
 * RM values go through the canonical parser/composer instead, so only the
 * branch-code SEGMENT can ever change and a value that is not a Nomor RM is
 * left alone.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH, and why:
 *
 *   trx_clinic_visits.visit_number   Issued transactional identifiers. They were
 *                                    printed on documents already handed to
 *                                    patients, nothing derives a branch from
 *                                    them (the generator reads the branch master
 *                                    and already emits the canonical code), and
 *                                    the uniqueness they carry is per branch and
 *                                    date. Rewriting them would invalidate paper
 *                                    already issued to buy nothing.
 *
 *   sys_audit_logs                   The audit trail states what happened at the
 *                                    time it happened. At that time the code WAS
 *                                    SUN4. Editing it would make the record
 *                                    false, which is the one thing an audit log
 *                                    may never be.
 *
 *   stg_legacy_patient_imports       A staging row records what a past import
 *                                    generated, alongside the raw and normalized
 *                                    payloads it generated it from. It is
 *                                    historical evidence of an import, not a
 *                                    live identifier, and its committed patient
 *                                    is migrated below in the row that IS live.
 *
 *   published legacy RME evidence    Immutable clinical evidence, including the
 *   and legacy odontogram records    source Nomor RM transcribed from the paper
 *                                    document. The document really does say
 *                                    SUN4; the archive's job is to say so.
 *                                    Reachability is preserved by alias-aware
 *                                    resolution, not by editing the evidence.
 *
 *   terminal rollout waves           A COMPLETED or CANCELLED wave is a record of
 *                                    an approval that was granted and closed. It
 *                                    authorizes nothing further, so there is
 *                                    nothing to keep working.
 *
 * FAIL CLOSED. Both collision checks abort the whole transaction rather than
 * overwrite, merge, delete or renumber anything. A collision is a master-data
 * decision for a human, and this migration refuses to make it.
 *
 * IDEMPOTENT. Every step selects only rows still holding a deprecated code, so a
 * second run is a no-op — and on production the branch rename step is ALREADY a
 * no-op on the very first run, because that half was done by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertNoBranchCodeCollision();
            $this->renameBranchCode();
            $this->migratePatientMedicalRecordNumbers();
            $this->migrateLiveRolloutBranchCodes();
        });
    }

    /**
     * Deliberately NOT reversible.
     *
     * A down() would have to turn `SPN4` back into `SUN4`, and the application
     * already ISSUES the canonical code: production's branch master has read
     * `SPN4` since before this migration shipped, so new patients, new visits
     * and new rollout state legitimately carry it. Nothing in the data
     * distinguishes "SPN4 because it was migrated" from "SPN4 because it was
     * created that way", so a reversal would corrupt every record created since
     * — silently filing new patients under a branch code that no longer exists.
     *
     * Rolling this back is therefore a restore-from-backup operation, which the
     * deploy takes immediately before migrating, and not a schema operation.
     */
    public function down(): void
    {
        // Intentionally a no-op. See the note above: reversing a canonical
        // rename cannot be done safely from the data alone.
    }

    /**
     * Refuse to proceed when the canonical code is already taken by a DIFFERENT
     * branch. Renaming into it would collapse two clinics into one identity, or
     * violate the unique index and abort mid-way.
     *
     * Production reaches the first early return: no row holds `SUN4` any more,
     * because the rename was applied by hand. That is the correct outcome — the
     * later steps still run and repair the halves that were missed.
     */
    private function assertNoBranchCodeCollision(): void
    {
        if (! Schema::hasTable('mst_branches')) {
            return;
        }

        $historical = BranchCodeAlias::SUNU_HISTORICAL;
        $canonical = BranchCodeAlias::SUNU_CANONICAL;

        $historicalRows = DB::table('mst_branches')->where('code', $historical)->pluck('id');
        $canonicalRows = DB::table('mst_branches')->where('code', $canonical)->pluck('id');

        if ($historicalRows->isEmpty()) {
            return;
        }

        if ($canonicalRows->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'REVISION-SUNU-BRANCH-CODE: branch code %s is already held by branch id(s) %s while branch id(s) %s still hold %s. '
                .'Two branch rows cannot be merged automatically — resolve the duplicate in Master Data Cabang first.',
                $canonical,
                $canonicalRows->implode(', '),
                $historicalRows->implode(', '),
                $historical,
            ));
        }

        if ($historicalRows->count() > 1) {
            throw new RuntimeException(sprintf(
                'REVISION-SUNU-BRANCH-CODE: branch code %s is held by more than one branch (ids %s). Resolve the duplicate before migrating.',
                $historical,
                $historicalRows->implode(', '),
            ));
        }
    }

    /**
     * Rename the branch code in place. The primary key, and therefore every
     * foreign key pointing at this branch, is untouched: this is one branch
     * identity changing its label, not a new branch.
     */
    private function renameBranchCode(): void
    {
        if (! Schema::hasTable('mst_branches')) {
            return;
        }

        DB::table('mst_branches')
            ->where('code', BranchCodeAlias::SUNU_HISTORICAL)
            ->update(['code' => BranchCodeAlias::SUNU_CANONICAL]);
    }

    /**
     * Rewrite the branch-code segment of every patient Nomor RM still issued
     * under the deprecated code — soft-deleted patients included, because the
     * unique index spans them and a restored patient must not resurface holding
     * a code that names no branch.
     *
     * Collision is checked against the whole table (again including soft-deleted
     * rows) before anything is written, so the unique index can never be the
     * thing that discovers the problem.
     */
    private function migratePatientMedicalRecordNumbers(): void
    {
        if (! Schema::hasTable('mst_patients')) {
            return;
        }

        /** @var PatientMedicalRecordNumberService $numbers */
        $numbers = app(PatientMedicalRecordNumberService::class);

        $candidates = DB::table('mst_patients')
            ->select('id', 'medical_record_number')
            ->whereNotNull('medical_record_number')
            ->where('medical_record_number', 'like', 'DG-'.BranchCodeAlias::SUNU_HISTORICAL.'-%')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $planned = [];

        foreach ($candidates as $candidate) {
            $current = (string) $candidate->medical_record_number;

            // Parser-driven, never a string replacement: this returns null for
            // anything that is not exactly DG-{KODE}-{TAHUN}-{NOMOR}, and only
            // ever substitutes the branch-code segment.
            $target = $numbers->canonicalizeBranchCode($current);

            if ($target === null || $target === $current) {
                // Not a canonical Nomor RM (so not ours to rewrite), or already
                // canonical. Leaving it untouched is the correct outcome for
                // both — the LIKE above is a coarse filter, the parser decides.
                continue;
            }

            $collidesWith = DB::table('mst_patients')
                ->where('medical_record_number', $target)
                ->where('id', '!=', $candidate->id)
                ->pluck('id');

            if ($collidesWith->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    'REVISION-SUNU-BRANCH-CODE: patient id %d cannot be migrated because its canonical Nomor RM is already held by patient id(s) %s. '
                    .'Two patients holding the same number is a master-data decision — no record has been changed.',
                    (int) $candidate->id,
                    $collidesWith->implode(', '),
                ));
            }

            $planned[(int) $candidate->id] = $target;
        }

        // Two passes on purpose: every collision is proven absent before the
        // first write, so a refusal can never leave a half-migrated table.
        foreach ($planned as $id => $target) {
            DB::table('mst_patients')->where('id', $id)->update(['medical_record_number' => $target]);
        }
    }

    /**
     * Canonicalize the branch code recorded on rollout state that is still LIVE.
     *
     * These rows are compared against the branch code derived from a patient's
     * Nomor RM, so a stale spelling here means an approved branch is refused
     * admission to its own wave. That is not hypothetical for Sunu: production's
     * ACTIVE wave listed `SUN4` while the branch master already answered `SPN4`.
     *
     * Terminal waves are skipped: they authorize nothing, and their record of
     * what was approved stays as it was written — production holds a CANCELLED
     * wave naming Telkomas' deprecated code, and it must stay that way.
     *
     * No collision guard is needed on the wave rows themselves: their unique
     * keys are `(wave_id, branch_id)` and `(wave_id, user_id, branch_id)`, so
     * `branch_code` is a denormalized LABEL beside the identity, not part of it.
     * One branch cannot appear twice on a wave under two spellings.
     */
    private function migrateLiveRolloutBranchCodes(): void
    {
        $historical = BranchCodeAlias::SUNU_HISTORICAL;
        $canonical = BranchCodeAlias::SUNU_CANONICAL;

        $liveWaveIds = [];

        if (Schema::hasTable('ops_rme_legacy_migration_waves')) {
            $liveWaveIds = DB::table('ops_rme_legacy_migration_waves')
                ->whereNotIn('status', LegacyRmeWaveStatus::TERMINAL)
                ->pluck('id')
                ->all();

            // `approved_branch_codes` is a JSON array. It is decoded, mapped and
            // re-encoded rather than string-replaced, so a token is only ever
            // swapped as a whole element and the document shape is preserved.
            $waves = DB::table('ops_rme_legacy_migration_waves')
                ->whereIn('id', $liveWaveIds ?: [0])
                ->select('id', 'approved_branch_codes')
                ->get();

            foreach ($waves as $wave) {
                $decoded = json_decode((string) $wave->approved_branch_codes, true);

                if (! is_array($decoded)) {
                    continue;
                }

                $mapped = array_values(array_map(
                    static fn ($code): string => (string) (BranchCodeAlias::canonicalize(is_scalar($code) ? (string) $code : '') ?? ''),
                    $decoded,
                ));

                if ($mapped === $decoded) {
                    continue;
                }

                DB::table('ops_rme_legacy_migration_waves')
                    ->where('id', $wave->id)
                    ->update(['approved_branch_codes' => json_encode($mapped)]);
            }
        }

        // No live wave means nothing here is live, so nothing here is rewritten.
        // The wave id list is ALWAYS applied — never conditionally — because a
        // branch row can carry a non-terminal status while the wave that owns it
        // is CANCELLED (production holds exactly that: a DRAINING branch row on a
        // cancelled wave). Dropping the wave filter when the list happens to be
        // empty would let the status filter alone reach that historical row.
        if ($liveWaveIds === []) {
            return;
        }

        if (Schema::hasTable('ops_rme_legacy_wave_branches')) {
            DB::table('ops_rme_legacy_wave_branches')
                ->where('branch_code', $historical)
                ->whereNotIn('status', LegacyRmeWaveBranchStatus::TERMINAL)
                ->whereIn('wave_id', $liveWaveIds)
                ->update(['branch_code' => $canonical]);
        }

        if (Schema::hasTable('ops_rme_legacy_wave_operators')) {
            // Operators carry no status of their own; a live wave is the gate.
            DB::table('ops_rme_legacy_wave_operators')
                ->where('branch_code', $historical)
                ->whereIn('wave_id', $liveWaveIds)
                ->update(['branch_code' => $canonical]);
        }
    }
};
