<?php

declare(strict_types=1);

namespace App\Modules\RME\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.12, PR A4) — the
 * plan and the apply behind `rme:doctor-assignments-reconcile`.
 *
 * The PLAN (read only) holds:
 *  - the stale `auto_visit` rows ({@see PatientDoctorAssignmentService::staleAutoVisitRows()},
 *    the predicate revocation uses), counted by what happened to the row's
 *    source visit, and the plan digest that binds exactly their ids;
 *  - the stale OPEN visits: an open status ({@see DoctorEncounterWindow::OPEN_STATUSES})
 *    on a clinical date before today (ClinicalClock, WITA), not soft-deleted —
 *    each keeps its doctor's encounter window open (§2.10 precondition);
 *  - the roomless PRE-EXAM canonical anchors: the patient's earliest
 *    non-cancelled RME visit (visit_date, id — exactly
 *    PatientRmWorkspaceResolver::resolveCanonicalWorkspaceVisit()) still
 *    registered, waiting or in_progress without a room (§2.11).
 * Visit ids are the only identifiers it holds; the console prints no
 * assignment id, name, NIK, Nomor RM or visit number.
 *
 * The APPLY ends the planned rows that are STILL stale, under their row locks,
 * by setting `unassigned_at` only (no other column, `updated_at` included;
 * never a delete), and writes one audit row naming them by id.
 */
final class DoctorAssignmentReconcileService
{
    public const AUDIT_ACTION = 'PATIENT_DOCTOR_ASSIGNMENTS_RECONCILED';

    /** At most this many visit ids per printed list. */
    public const ID_LIST_LIMIT = 200;

    private const DIGEST_PREFIX = 'rme:doctor-assignments-reconcile|v1|';

    public function __construct(
        private readonly PatientDoctorAssignmentService $assignments,
        private readonly BranchService $branches,
        private readonly ClinicalClock $clock,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array{
     *     today: string,
     *     stale_ids: list<int>,
     *     digest: string,
     *     counts: array<string, int>,
     *     stale_open_visit_ids: list<int>,
     *     roomless_anchor_ids: list<int>
     * }
     */
    public function plan(): array
    {
        $today = $this->clock->todayString();

        $stale = $this->assignments->staleAutoVisitRows()
            ->leftJoin('trx_clinic_visits as source_visit', 'source_visit.id', '=', 'trx_rme_patient_doctor_assignments.source_visit_id')
            ->orderBy('trx_rme_patient_doctor_assignments.id')
            ->get([
                'trx_rme_patient_doctor_assignments.id',
                'source_visit.id as source_id',
                'source_visit.status as source_status',
                'source_visit.deleted_at as source_deleted_at',
            ]);

        $causes = ['source_doctor_changed' => 0, 'source_cancelled' => 0, 'source_missing' => 0];

        foreach ($stale as $row) {
            // The row is stale, so a live source visit can only have another doctor.
            $cause = match (true) {
                $row->source_id === null || $row->source_deleted_at !== null => 'source_missing',
                $row->source_status === ClinicVisit::STATUS_CANCELLED => 'source_cancelled',
                default => 'source_doctor_changed',
            };
            $causes[$cause]++;
        }

        $staleIds = $stale->map(fn ($row): int => (int) $row->id)->values()->all();

        $openStale = ClinicVisit::query()
            ->whereIn('status', DoctorEncounterWindow::OPEN_STATUSES)
            ->where('visit_date', '<', $today)
            ->orderBy('id')
            ->get(['id', 'status', 'doctor_id']);
        $withDoctor = $openStale->filter(fn (ClinicVisit $visit): bool => $visit->doctor_id !== null);

        $anchors = $this->roomlessPreExamAnchors()->get(['id', 'visit_date']);

        $counts = [
            'active_auto_visit' => PatientDoctorAssignment::query()
                ->where('assignment_type', PatientDoctorAssignment::TYPE_AUTO_VISIT)
                ->whereNull('unassigned_at')
                ->count(),
            'stale_auto_visit' => count($staleIds),
            'stale_auto_visit_source_doctor_changed' => $causes['source_doctor_changed'],
            'stale_auto_visit_source_cancelled' => $causes['source_cancelled'],
            'stale_auto_visit_source_missing' => $causes['source_missing'],
            'active_other_types_untouched' => PatientDoctorAssignment::query()
                ->where('assignment_type', '!=', PatientDoctorAssignment::TYPE_AUTO_VISIT)
                ->whereNull('unassigned_at')
                ->count(),
            'stale_open_visits' => $withDoctor->count(),
        ];

        foreach (DoctorEncounterWindow::OPEN_STATUSES as $status) {
            $counts['stale_open_visits_'.$status] = $withDoctor->where('status', $status)->count();
        }

        $counts['stale_open_visits_without_doctor'] = $openStale->count() - $withDoctor->count();
        $counts['roomless_pre_exam_anchors'] = $anchors->count();
        $counts['roomless_pre_exam_anchors_before_today'] = $anchors
            ->filter(fn (ClinicVisit $anchor): bool => $anchor->visit_date->toDateString() < $today)
            ->count();

        return [
            'today' => $today,
            'stale_ids' => $staleIds,
            'digest' => self::digestFor($staleIds),
            'counts' => $counts,
            'stale_open_visit_ids' => $withDoctor->map(fn (ClinicVisit $visit): int => (int) $visit->id)->values()->all(),
            'roomless_anchor_ids' => $anchors->map(fn (ClinicVisit $anchor): int => (int) $anchor->id)->values()->all(),
        ];
    }

    /**
     * The digest of a plan: exactly the stale row ids it would end. A row that
     * goes stale (or stops being stale) after the review changes it, so an
     * apply never acts on a set the owner did not see.
     *
     * @param  list<int>  $staleIds
     */
    public static function digestFor(array $staleIds): string
    {
        sort($staleIds);

        return hash('sha256', self::DIGEST_PREFIX.implode(',', $staleIds));
    }

    /**
     * End the planned rows that are still stale. One transaction: the rows are
     * locked and re-checked against the stale predicate, `unassigned_at` is set
     * through the base query builder (so no other column, `updated_at`
     * included, changes), and one audit row names them by id.
     *
     * @param  array{stale_ids: list<int>, digest: string}  $plan
     * @return array{ended: int, skipped: int, ended_ids: list<int>}
     */
    public function apply(array $plan): array
    {
        return DB::transaction(function () use ($plan): array {
            $endedIds = [];

            foreach (array_chunk($plan['stale_ids'], 500) as $chunk) {
                $still = $this->assignments->staleAutoVisitRows()
                    ->whereIn('trx_rme_patient_doctor_assignments.id', $chunk)
                    ->orderBy('trx_rme_patient_doctor_assignments.id')
                    ->lockForUpdate()
                    ->pluck('trx_rme_patient_doctor_assignments.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                if ($still === []) {
                    continue;
                }

                PatientDoctorAssignment::query()
                    ->whereKey($still)
                    ->whereNull('unassigned_at')
                    ->toBase()
                    ->update(['unassigned_at' => now()]);

                array_push($endedIds, ...$still);
            }

            $result = [
                'ended' => count($endedIds),
                'skipped' => count($plan['stale_ids']) - count($endedIds),
                'ended_ids' => $endedIds,
            ];

            // Ids and counts only (CG-11); no actor account: the command runs on
            // the host as the runtime user after an owner-approved dry run.
            $this->auditLogs->log(PatientDoctorAssignmentService::AUDIT_TABLE, null, self::AUDIT_ACTION, null, [
                'plan_digest' => $plan['digest'],
                'planned' => count($plan['stale_ids']),
                'ended' => $result['ended'],
                'skipped' => $result['skipped'],
                'assignment_ids' => $endedIds,
            ]);

            return $result;
        });
    }

    /**
     * The roomless pre-exam canonical anchors, as visits. The NOT EXISTS is the
     * SQL twin of resolveCanonicalWorkspaceVisit(): no earlier (visit_date, id)
     * non-cancelled, non-deleted RME visit of the same patient.
     *
     * @return Builder<ClinicVisit>
     */
    private function roomlessPreExamAnchors(): Builder
    {
        $rme = $this->branches->rmeEnabledIds();

        return ClinicVisit::query()
            ->whereIn('trx_clinic_visits.branch_id', $rme)
            ->whereIn('trx_clinic_visits.status', ClinicVisit::PRE_EXAM_STATUSES)
            ->whereNull('trx_clinic_visits.clinic_room_id')
            ->whereNotExists(function (QueryBuilder $earlier) use ($rme): void {
                $earlier->selectRaw('1')
                    ->from('trx_clinic_visits as earlier_visit')
                    ->whereColumn('earlier_visit.patient_id', 'trx_clinic_visits.patient_id')
                    ->whereIn('earlier_visit.branch_id', $rme)
                    ->where('earlier_visit.status', '!=', ClinicVisit::STATUS_CANCELLED)
                    ->whereNull('earlier_visit.deleted_at')
                    ->where(function (QueryBuilder $before): void {
                        $before->whereColumn('earlier_visit.visit_date', '<', 'trx_clinic_visits.visit_date')
                            ->orWhere(function (QueryBuilder $sameDay): void {
                                $sameDay->whereColumn('earlier_visit.visit_date', '=', 'trx_clinic_visits.visit_date')
                                    ->whereColumn('earlier_visit.id', '<', 'trx_clinic_visits.id');
                            });
                    });
            })
            ->orderBy('trx_clinic_visits.id');
    }
}
