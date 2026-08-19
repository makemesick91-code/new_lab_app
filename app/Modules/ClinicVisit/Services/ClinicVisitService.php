<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\PatientDoctorAssignmentService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicVisitService
{
    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly BranchContext $branchContext,
        private readonly PatientService $patients,
        private readonly BranchService $branches,
        private readonly UserOnlineContextService $onlineContext,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly PatientDoctorAssignmentService $patientDoctorAssignments,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * Daftar Kunjungan RME lists visits across the operational "Cabang RME" set
     * (active RME-enabled branches), NOT a single BranchContext fallback branch.
     * MAIN is excluded because it is not RME-enabled. An optional `branch_id`
     * filter narrows the scope to a single RME branch; any value outside the
     * RME set is ignored and the full RME scope is used (Sprint 23 Phase 23.9.3).
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->visits->paginateForBranches(
            $this->scopeBranchIds($filters['branch_id'] ?? null),
            $filters,
            $perPage,
            $this->doctorVisitScope(),
        );
    }

    /**
     * Resolve the branch IDs a visit list / count should be scoped to.
     *
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-04) — delegated to the canonical
     * {@see RmeWorkingBranchScope}. A context-bound operational role (Admin
     * Klinik, Perawat, Kasir) is pinned to its selected working branch and fails
     * closed to an EMPTY scope when it has no valid context; governance roles
     * keep the full active RME-enabled set. The optional `branch_id` filter may
     * only NARROW the authorised scope, never widen it, so a crafted
     * `?branch_id=` can never reach another branch. Every visit list, queue,
     * worklist and count widget funnels through here, so the scope also covers
     * aggregates and exports.
     *
     * @return array<int, int>
     */
    private function scopeBranchIds(?int $branchFilter): array
    {
        return $this->workingBranchScope->resolve(Auth::user(), $branchFilter);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-06) — Daftar Kunjungan defaults to
     * the clinic's own calendar day. Historical visits stay reachable, but only
     * when the user explicitly touches a date filter. "Explicit" means the
     * request actually carries one of the date keys — clearing the field (an
     * empty submitted value) is itself an explicit request for the full history.
     *
     * The clinical day comes from {@see ClinicalClock} (Asia/Makassar), never
     * from a raw UTC now().
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function applyVisitIndexDateDefault(array $filters, bool $hasExplicitDateFilter): array
    {
        $filters['defaulted_to_today'] = false;

        if ($hasExplicitDateFilter) {
            return $filters;
        }

        $filters['visit_date'] = $this->clock->todayString();
        $filters['defaulted_to_today'] = true;

        return $filters;
    }

    /**
     * The RME branches this user may actually choose between — used for the
     * branch filter selector so a context-bound role is never offered a branch
     * it cannot read.
     *
     * @return Collection<int, Branch>
     */
    public function selectableRmeBranches(): Collection
    {
        $allowed = $this->workingBranchScope->branchIdsFor(Auth::user());

        return $this->branches->listRmeEnabled()
            ->filter(fn (Branch $branch) => in_array((int) $branch->id, $allowed, true))
            ->values();
    }

    /**
     * Doctor/Perawat treatment room worklist: room-assigned, non-terminal visits
     * across the active RME-enabled branch set (optionally narrowed to one RME
     * branch). Never falls back to a single BranchContext branch.
     */
    public function roomWorklist(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->visits->worklistForBranches(
            $this->scopeBranchIds($filters['branch_id'] ?? null),
            $filters,
            $perPage,
            $this->doctorVisitScope(),
        );
    }

    /**
     * Sprint 58.7 — Antrian Pasien. Registered-patient queue: active (non-terminal)
     * visits across the active RME-enabled branch set (optionally narrowed to one
     * RME branch), including both room-assigned and not-yet-assigned visits. Never
     * falls back to a single BranchContext branch.
     */
    public function registeredQueue(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->visits->queueForBranches(
            $this->scopeBranchIds($filters['branch_id'] ?? null),
            $filters,
            $perPage,
            $this->doctorVisitScope(),
        );
    }

    /**
     * Active treatment rooms for the active RME-enabled branch set, grouped by
     * branch_id, for the queue's per-row room assignment selector.
     *
     * @return Collection<int, Collection<int, ClinicRoom>>
     */
    public function activeRoomsByRmeBranch(): Collection
    {
        return ClinicRoom::query()
            ->whereIn('branch_id', $this->branches->rmeEnabledIds())
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->groupBy('branch_id');
    }

    /**
     * Active treatment rooms for a single branch, used by the visit detail page
     * room-assignment selector. Scoped strictly to the visit's own branch so a
     * room from another branch (or MAIN) can never be offered.
     *
     * @return Collection<int, ClinicRoom>
     */
    public function activeRoomsForBranch(int $branchId): Collection
    {
        return ClinicRoom::query()
            ->where('branch_id', $branchId)
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * Assign a treatment room to a visit. The room must be active and belong to
     * the same branch as the visit — rooms from other branches are rejected.
     */
    public function assignRoom(ClinicVisit $visit, int $roomId): ClinicVisit
    {
        // Hotfix Sprint 60.8 — room assignment is a queue-stage action. A visit
        // that has already been completed or cancelled is past the workflow and
        // its room must not be reassigned.
        if ($visit->isTerminal()) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Kunjungan yang sudah selesai atau dibatalkan tidak dapat diubah ruangannya.',
            ]);
        }

        $room = ClinicRoom::query()->find($roomId);

        if ($room === null || $room->status !== ClinicRoom::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Ruangan tidak ditemukan atau tidak aktif.',
            ]);
        }

        if ((int) $room->branch_id !== (int) $visit->branch_id) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Ruangan harus berasal dari cabang yang sama dengan kunjungan.',
            ]);
        }

        $doctorId = $this->onlineContext->resolveDoctorIdForRoom((int) $visit->branch_id, $room->id);

        return DB::transaction(function () use ($visit, $room, $doctorId) {
            $updated = $this->visits->update($visit, [
                'clinic_room_id' => $room->id,
                'doctor_id' => $doctorId,
            ]);

            if ($doctorId !== null) {
                $this->patientDoctorAssignments->ensureAssignedFromVisit($updated->fresh(), Auth::user());
            }

            return $updated;
        });
    }

    public function find(int $id): ?ClinicVisit
    {
        return $this->visits->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): ClinicVisit
    {
        return DB::transaction(function () use ($data) {
            // RME "Klinik" = "Cabang RME". The visit branch follows the selected
            // RME-enabled branch (Sprint 23 Phase 23.9.1). For new patients the
            // patient and the visit share the same branch. The BranchContext
            // fallback only applies to legacy callers that submit no branch.
            $branchId = $this->resolveBranchId($data);
            $data['branch_id'] = $branchId;

            $data = $this->resolvePatient($data);

            unset($data['branch_id']);

            $visitDate = Carbon::today();

            $queueNumber = $this->visits->nextQueueNumber($branchId, $visitDate);
            $visitNumber = $this->generateUniqueVisitNumber($branchId, $visitDate);

            return $this->visits->create(array_merge($data, [
                'branch_id' => $branchId,
                'visit_date' => $visitDate->toDateString(),
                'queue_number' => $queueNumber,
                'visit_number' => $visitNumber,
                'status' => ClinicVisit::STATUS_REGISTERED,
                'visit_type' => $data['visit_type'] ?? ClinicVisit::VISIT_TYPE_NEW,
                'follow_up_of_visit_id' => $data['follow_up_of_visit_id'] ?? null,
                'created_by' => Auth::id(),
            ]));
        });
    }

    /**
     * Patient visit history scoped to active RME-enabled branches.
     */
    public function patientVisitHistory(int $patientId, ?int $excludeVisitId = null): Collection
    {
        return $this->visits->listForPatient(
            $this->branches->rmeEnabledIds(),
            $patientId,
            $excludeVisitId,
        );
    }

    /**
     * Previous/next visit for the same patient (chronological), used for the
     * prev/next arrow navigation on the RM and Odontogram pages. Scoped to the
     * active RME branch set, mirroring patientVisitHistory access (Sprint 59).
     *
     * @return array{previous: ?ClinicVisit, next: ?ClinicVisit}
     */
    public function adjacentVisits(ClinicVisit $visit, bool $requireMedicalRecord = false): array
    {
        return $this->visits->adjacentVisitsForPatient(
            $this->branches->rmeEnabledIds(),
            $visit,
            $requireMedicalRecord,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function patientVisitOptions(int $patientId, ?int $excludeVisitId = null): array
    {
        return $this->patientVisitHistory($patientId, $excludeVisitId)
            ->map(fn (ClinicVisit $visit) => [
                'id' => $visit->id,
                'visit_number' => $visit->visit_number,
                'visit_date' => $visit->visit_date?->format('d/m/Y'),
                'visit_type' => $visit->visit_type,
                'visit_type_label' => $visit->visitTypeLabel(),
                'doctor_name' => $visit->doctor?->name,
                'initial_treatment' => $visit->initialTreatment?->name,
                'status' => $visit->status,
            ])
            ->values()
            ->all();
    }

    /**
     * Generate a globally unique visit number for the visit date and branch.
     *
     * Format:
     *   VIS-{BRANCHCODE}-{YYYYMMDD}-{NNN}
     *
     * Queue numbers remain branch/date scoped, while visit_number has a global
     * unique constraint. Including the branch code prevents cross-branch
     * collisions such as multiple branches generating VIS-YYYYMMDD-001.
     */
    private function generateUniqueVisitNumber(int $branchId, Carbon $visitDate): string
    {
        $branchCode = $this->resolveVisitBranchCode($branchId);
        $prefix = 'VIS-'.$branchCode.'-'.$visitDate->format('Ymd').'-';

        $maxSequence = ClinicVisit::query()
            ->where('visit_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('visit_number')
            ->map(function (string $visitNumber) use ($prefix): int {
                $suffix = str_replace($prefix, '', $visitNumber);

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;

        $sequence = $maxSequence + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $sequence++;
        } while (ClinicVisit::query()->where('visit_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Resolve a safe branch code for visit_number.
     *
     * Prefer explicit code fields when available. If the branch table does not
     * have a code column yet, fall back to the branch name, then BR{id}.
     */
    private function resolveVisitBranchCode(int $branchId): string
    {
        $branch = Branch::query()->find($branchId);

        $rawCode = $branch?->code
            ?? $branch?->branch_code
            ?? $branch?->slug
            ?? $branch?->name
            ?? 'BR'.$branchId;

        $code = preg_replace('/[^A-Z0-9]/', '', Str::upper((string) $rawCode));

        if ($code === null || $code === '') {
            $code = 'BR'.$branchId;
        }

        return Str::limit($code, 8, '');
    }

    /**
     * Resolve the branch the visit belongs to ("Klinik" = "Cabang RME").
     *
     * Precedence:
     *   1. New-patient mode: the branch chosen for the new patient, so the
     *      patient and the visit always share one RME branch.
     *   2. Existing-patient mode: an explicitly selected RME branch.
     *
     * Never falls back to BranchContext/MAIN — RME visits must belong to an
     * active RME-enabled branch (Sprint 23 Phase 23.10 hardening).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveBranchId(array $data): int
    {
        $branchId = null;

        if (($data['patient_mode'] ?? 'existing') === 'new') {
            $branchId = $data['new_patient']['branch_id'] ?? null;
        } else {
            $branchId = $data['branch_id'] ?? null;
        }

        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang RME wajib dipilih untuk kunjungan RME.',
            ]);
        }

        $branchId = (int) $branchId;

        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Klinik/Cabang yang dipilih harus cabang RME aktif.',
            ]);
        }

        return $branchId;
    }

    /**
     * Resolve the visit's patient_id. In "new" mode a patient is created first
     * with the finalized RM number, then the visit is attached to it. In
     * "existing" mode the supplied patient_id is used unchanged. The transient
     * registration keys are stripped before the visit is persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvePatient(array $data): array
    {
        $mode = $data['patient_mode'] ?? 'existing';
        $newPatient = $data['new_patient'] ?? null;

        unset($data['patient_mode'], $data['new_patient']);

        if ($mode === 'new' && is_array($newPatient)) {
            $patient = $this->patients->create([
                // RME patients are branch-scoped, not clinic-scoped. clinic_id is
                // intentionally left null (legacy column, now nullable).
                'clinic_id' => $data['clinic_id'] ?? null,
                'doctor_id' => $data['doctor_id'],
                'branch_id' => $newPatient['branch_id'] ?? $data['branch_id'] ?? null,
                'registered_at' => $newPatient['registered_at'] ?? null,
                'manual_rm_number' => $newPatient['manual_rm_number'] ?? null,
                'name' => $newPatient['name'] ?? null,
                'gender' => $newPatient['gender'] ?? null,
                'date_of_birth' => $newPatient['date_of_birth'] ?? null,
                'phone' => $newPatient['phone'] ?? null,
                'whatsapp_number' => $newPatient['whatsapp_number'] ?? null,
                'ktp_number' => $newPatient['ktp_number'] ?? null,
                'email' => $newPatient['email'] ?? null,
                'occupation' => $newPatient['occupation'] ?? null,
                'address' => $newPatient['address'] ?? null,
                'is_active' => true,
            ]);

            $data['patient_id'] = $patient->id;
        }

        return $data;
    }

    public function update(ClinicVisit $visit, array $data): ClinicVisit
    {
        unset($data['status']);

        return DB::transaction(fn () => $this->visits->update($visit, $data));
    }

    public function visitsTodayCount(?int $branchId = null): int
    {
        return $this->visits->countTodayByBranches(
            $this->scopeBranchIds($branchId),
            // FIX-06 — the clinic's own calendar day, not a UTC now().
            $this->clock->todayString(),
        );
    }

    public function waitingCount(?int $branchId = null): int
    {
        return $this->visits->countByBranchesStatus(
            $this->scopeBranchIds($branchId),
            ClinicVisit::STATUS_WAITING,
        );
    }

    public function inProgressCount(?int $branchId = null): int
    {
        return $this->visits->countByBranchesStatus(
            $this->scopeBranchIds($branchId),
            ClinicVisit::STATUS_IN_PROGRESS,
        );
    }

    public function transitionStatus(ClinicVisit $visit, string $newStatus): ClinicVisit
    {
        // Sprint 62.1 — Doctor → Cashier completion gate. A visit may only become
        // `completed` ("Selesai Visit") from `cashier_pending`, i.e. after the
        // cashier has settled the invoice (RmePaymentService is the only caller
        // that reaches this from cashier_pending). The doctor/front office can
        // never skip the cashier and mark a visit fully completed.
        if ($newStatus === ClinicVisit::STATUS_COMPLETED
            && $visit->status !== ClinicVisit::STATUS_CASHIER_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Visit belum dapat diselesaikan total karena pembayaran belum selesai.',
            ]);
        }

        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-05) — "Selesai Pemeriksaan" is a
        // clinical act. Enforced here as well as at the route, so a non-HTTP
        // caller (command, job, another service) cannot close an examination on
        // behalf of a user who may not. Unauthenticated/system callers are not
        // affected, and the cashier-owned `completed` transition is untouched.
        if ($newStatus === ClinicVisit::STATUS_CASHIER_PENDING) {
            $actor = Auth::user();

            if ($actor !== null && $actor->cannot('completeExamination', $visit)) {
                throw ValidationException::withMessages([
                    'status' => 'Anda tidak berwenang menyelesaikan pemeriksaan. Tindakan ini dilakukan oleh dokter.',
                ]);
            }
        }

        $allowed = ClinicVisit::VALID_TRANSITIONS[$visit->status] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Transisi status dari '{$visit->status}' ke '{$newStatus}' tidak diizinkan.",
            ]);
        }

        return DB::transaction(function () use ($visit, $newStatus) {
            $timestamps = [];

            if ($newStatus === ClinicVisit::STATUS_WAITING && $visit->check_in_at === null) {
                $timestamps['check_in_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_IN_PROGRESS && $visit->started_at === null) {
                $timestamps['started_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_COMPLETED && $visit->completed_at === null) {
                $timestamps['completed_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_CANCELLED && $visit->cancelled_at === null) {
                $timestamps['cancelled_at'] = now();
            }

            return $this->visits->update($visit, array_merge(['status' => $newStatus], $timestamps));
        });
    }

    private function doctorVisitScope(): ?\Closure
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return fn ($query) => $this->doctorScope->applyVisitScopeForUser($user, $query);
    }
}
