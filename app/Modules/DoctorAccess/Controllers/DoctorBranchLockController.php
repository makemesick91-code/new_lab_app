<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchCoverRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRequestRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;
use App\Modules\DoctorAccess\Requests\DecideDoctorBranchCoverRequest;
use App\Modules\DoctorAccess\Requests\DecideDoctorBranchLockRequestRequest;
use App\Modules\DoctorAccess\Requests\ReleaseDoctorSessionRequest;
use App\Modules\DoctorAccess\Requests\StoreDoctorBranchCoverRequest;
use App\Modules\DoctorAccess\Requests\StoreDoctorBranchLockRequestRequest;
use App\Modules\DoctorAccess\Services\DoctorBranchCoverApprovalService;
use App\Modules\DoctorAccess\Services\DoctorBranchLockApprovalService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionReleaseService;
use App\Modules\DoctorAccess\Support\DoctorBranchCoverState;
use App\Modules\DoctorAccess\Support\DoctorEffectiveBranch;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the HTTP surface for a doctor's
 * permanent home branch, temporary branch cover, and the approver's
 * lease-release action.
 *
 * THIN BY CONTRACT. Authorize, hand the FormRequest's validated values to a
 * service, redirect with a flash. Every rule about what may move, when, and by
 * whom lives in {@see DoctorBranchLockApprovalService},
 * {@see DoctorBranchCoverApprovalService} and
 * {@see DoctorSessionReleaseService}, so a future console command and this
 * surface cannot diverge. Nothing rendered here is a boundary: the approval
 * transactions re-read presence, the doctor row, the live lock and the
 * destination branch under a row lock, so a screen rendered ten minutes ago can
 * never decide anything.
 *
 * THE FEATURE GATE IS THE SIBLING RESOLVER'S PREDICATE, NOT A COPY OF IT.
 * `DoctorEffectiveBranchResolver::enabled()` requires `doctor.branch_lock`,
 * `doctor.single_active_session` AND an observable incumbent-session probe.
 * Re-reading the flags here would put the same predicate in two places, which
 * is the drift that predicate exists to prevent. It answers 404 rather than
 * 403 so a capability that is switched off does not advertise itself.
 */
class DoctorBranchLockController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorBranchLockApprovalService $lockApprovals,
        private readonly DoctorBranchCoverApprovalService $coverApprovals,
        private readonly DoctorSessionReleaseService $sessionReleases,
        private readonly DoctorEffectiveBranchResolver $effectiveBranch,
        private readonly DoctorBranchLockRequestRepositoryInterface $requests,
        private readonly DoctorBranchCoverRepositoryInterface $covers,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly DoctorRepositoryInterface $doctors,
        private readonly BranchService $branches,
        private readonly UserOnlineContextService $presence,
        private readonly DoctorIdentityResolver $identities,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * The subject's own view: their home branch today, their pending request if
     * one exists, and the form to file a new one.
     */
    public function create(Request $request): View
    {
        $this->assertCapabilityArmed();
        $this->authorize('create', DoctorBranchLockRequest::class);

        $user = $request->user();
        $mayFileForOthers = $this->mayFileForOthers($user);
        $selfDoctor = $this->identities->resolveForUser($user);
        $subjectDoctorId = $mayFileForOthers ? null : ($selfDoctor === null ? null : (int) $selfDoctor->id);

        return view('rme.doctor-branch-locks.create', [
            'mayFileForOthers' => $mayFileForOthers,
            'selfDoctor' => $selfDoctor,
            'doctors' => $mayFileForOthers ? $this->doctors->listAll() : collect(),
            'branches' => $this->branches->listRmeEnabled(),
            'currentLock' => $subjectDoctorId === null
                ? null
                : $this->locks->findForDoctor($subjectDoctorId),
            'pendingRequest' => $subjectDoctorId === null
                ? null
                : $this->requests->findPendingForDoctor($subjectDoctorId),
            'history' => $subjectDoctorId === null
                ? collect()
                : $this->requests->forDoctor($subjectDoctorId),
        ]);
    }

    /**
     * THE IDOR BOUNDARY. A submitted `doctor_id` is honoured only for an actor
     * who may file on somebody else's behalf; for anybody else it is discarded
     * and replaced by their own linked doctor record. The FormRequest's
     * `exists` rule is a usability filter, never the boundary.
     */
    public function store(StoreDoctorBranchLockRequestRequest $request): RedirectResponse
    {
        $this->assertCapabilityArmed();
        $this->authorize('create', DoctorBranchLockRequest::class);

        $this->lockApprovals->request(
            $request->user(),
            $this->resolveSubjectDoctorId($request),
            (int) $request->validated('destination_branch_id'),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.create')
            ->with('status', 'Permintaan kunci cabang dikirim. Menunggu persetujuan.');
    }

    public function cancel(Request $request, DoctorBranchLockRequest $doctorBranchLockRequest): RedirectResponse
    {
        $this->assertCapabilityArmed();
        $this->authorize('cancel', $doctorBranchLockRequest);

        $this->lockApprovals->cancel((int) $doctorBranchLockRequest->id, $request->user());

        return redirect()
            ->route('rme.doctor-branch-locks.create')
            ->with('status', 'Permintaan kunci cabang dibatalkan.');
    }

    /**
     * The approver queue.
     *
     * ONE INSTANT is resolved here and handed to every derived state on the
     * page, so the screen and the decision it leads to can never disagree
     * because a second ticked over between two renders.
     *
     * TWO THINGS THIS PAGE EXISTS TO MAKE VISIBLE:
     *
     *  - RULING P11 — the subject doctor's LIVE presence (online, and which
     *    branch and room they are working in), because approving evicts them.
     *  - FINDING U3 — every doctor whose lock has silently STOPPED APPLYING.
     *    `DoctorEffectiveBranch::degraded()` answers a null branch on purpose,
     *    so a home branch that loses `is_active` or `is_rme_enabled` lets
     *    legacy behaviour resume instead of stopping the doctor dead. That is
     *    the correct degradation and it is deliberately NOT audited by the
     *    resolver, which runs on every protected request. Rendering it here is
     *    what makes it visible rather than silent.
     */
    public function index(Request $request): View
    {
        $this->assertCapabilityArmed();
        $this->authorize('viewAny', DoctorBranchLockRequest::class);

        $now = CarbonImmutable::now();
        $pendingRequests = $this->requests->pending();
        $pendingCovers = $this->covers->pending();
        $currentLocks = $this->locks->withDoctorAndBranch();
        $decidedCovers = $this->covers->recentlyDecided();

        // The lock repository eager-loads the doctor but not their account, and
        // both the presence reading and the degraded-lock reading need it. One
        // extra query here instead of one per row.
        $currentLocks->loadMissing('doctor.user');

        $subjects = $pendingRequests->pluck('doctor')
            ->concat($pendingCovers->pluck('doctor'))
            ->concat($currentLocks->pluck('doctor'))
            ->filter()
            ->unique('id');

        return view('rme.doctor-branch-locks.index', [
            'clinicalTimezone' => $this->clock->timezone(),
            // The minimum a rejection or a cancellation reason must satisfy,
            // read here rather than in the Blade so the form's placeholder and
            // the two services enforcing it cannot drift apart.
            'reasonMinLength' => max(1, (int) config('doctor_access.reason.min_length', 10)),
            'pendingRequests' => $pendingRequests,
            'pendingCovers' => $pendingCovers,
            'currentLocks' => $currentLocks,
            'decidedRequests' => $this->requests->recentlyDecided(),
            'decidedCovers' => $decidedCovers,
            'coverStates' => $this->coverStateLabels($decidedCovers, $now),
            'presence' => $this->presenceSnapshot($subjects),
            'activeCovers' => $this->activeCoverSnapshot($currentLocks, $now),
            'degradedLocks' => $this->degradedLockRows($currentLocks, $now),
            'mayDecide' => $request->user()->can('approve_doctor_branch_locks'),
            'mayReleaseSession' => $request->user()->can('release_doctor_session_leases'),
        ]);
    }

    public function approve(
        DecideDoctorBranchLockRequestRequest $request,
        DoctorBranchLockRequest $doctorBranchLockRequest,
    ): RedirectResponse {
        $this->assertCapabilityArmed();
        $this->authorize('decide', $doctorBranchLockRequest);

        $approved = $this->lockApprovals->approve(
            (int) $doctorBranchLockRequest->id,
            $request->user(),
            $request->validated('decision_note'),
            $request->boolean('acknowledge_online_impact'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', 'Permintaan disetujui. Cabang tetap dokter dipindahkan ke '
                .($approved->destinationBranch?->name ?? 'cabang tujuan')
                .' dan sesi login dokter diakhiri.');
    }

    public function reject(
        DecideDoctorBranchLockRequestRequest $request,
        DoctorBranchLockRequest $doctorBranchLockRequest,
    ): RedirectResponse {
        $this->assertCapabilityArmed();
        $this->authorize('decide', $doctorBranchLockRequest);

        $this->lockApprovals->reject(
            (int) $doctorBranchLockRequest->id,
            $request->user(),
            $request->validated('decision_note'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', 'Permintaan ditolak. Cabang tetap dokter tidak berubah dan sesi dokter tidak diakhiri.');
    }

    /**
     * Filing a temporary cover. Approver tier only — a doctor can never file
     * cover, for themself or anybody else, and the service repeats that
     * comparison inside its transaction.
     */
    public function coverCreate(Request $request): View
    {
        $this->assertCapabilityArmed();
        $this->authorize('create', DoctorBranchCover::class);

        $now = CarbonImmutable::now();
        $selectedDoctorId = $request->integer('doctor_id') ?: null;
        $existingCovers = $selectedDoctorId === null
            ? collect()
            : $this->covers->approvedForDoctor($selectedDoctorId);

        return view('rme.doctor-branch-locks.cover-create', [
            'clinicalTimezone' => $this->clock->timezone(),
            'doctors' => $this->doctors->listAll(),
            'branches' => $this->branches->listRmeEnabled(),
            'homeBranchNames' => $this->homeBranchNames(),
            'selectedDoctorId' => $selectedDoctorId,
            'existingCovers' => $existingCovers,
            'coverStates' => $this->coverStateLabels($existingCovers, $now),
            'maxDays' => (int) config('doctor_access.cover.max_days', 90),
        ]);
    }

    public function coverStore(StoreDoctorBranchCoverRequest $request): RedirectResponse
    {
        $this->assertCapabilityArmed();
        $this->authorize('create', DoctorBranchCover::class);

        $doctorId = (int) $request->validated('doctor_id');

        $this->coverApprovals->request(
            $request->user(),
            $doctorId,
            (int) $request->validated('target_branch_id'),
            (string) $request->validated('starts_at'),
            (string) $request->validated('ends_at'),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('rme.doctor-branch-covers.create', ['doctor_id' => $doctorId])
            ->with('status', 'Pengajuan cover cabang dikirim. Menunggu persetujuan.');
    }

    public function coverApprove(
        DecideDoctorBranchCoverRequest $request,
        DoctorBranchCover $doctorBranchCover,
    ): RedirectResponse {
        $this->assertCapabilityArmed();
        $this->authorize('decide', $doctorBranchCover);

        $approved = $this->coverApprovals->approve(
            (int) $doctorBranchCover->id,
            $request->user(),
            $request->validated('decision_note'),
            $request->boolean('acknowledge_online_impact'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', 'Cover disetujui untuk cabang '
                .($approved->targetBranch?->name ?? 'tujuan')
                .'. Sesi login dokter diakhiri; cabang tetap dokter tidak berubah.');
    }

    public function coverReject(
        DecideDoctorBranchCoverRequest $request,
        DoctorBranchCover $doctorBranchCover,
    ): RedirectResponse {
        $this->assertCapabilityArmed();
        $this->authorize('decide', $doctorBranchCover);

        $this->coverApprovals->reject(
            (int) $doctorBranchCover->id,
            $request->user(),
            $request->validated('decision_note'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', 'Pengajuan cover ditolak. Cabang dokter tidak berubah.');
    }

    /**
     * Withdraw a cover — the escape hatch that stops a long cover jamming the
     * permanent-transfer workflow. Whether the withdrawal evicts the doctor is
     * the service's decision: a cover cancelled while it was in force does, a
     * merely scheduled one does not.
     */
    public function coverCancel(
        DecideDoctorBranchCoverRequest $request,
        DoctorBranchCover $doctorBranchCover,
    ): RedirectResponse {
        $this->assertCapabilityArmed();
        $this->authorize('cancel', $doctorBranchCover);

        $this->coverApprovals->cancel(
            (int) $doctorBranchCover->id,
            $request->user(),
            (string) $request->validated('cancellation_reason', ''),
            $request->user()->can('approve_doctor_branch_locks'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', 'Cover dibatalkan.');
    }

    /**
     * RULING P17 — an on-site approver clears a colleague's stuck lease without
     * an SSH session.
     *
     * IT ENDS A LOGIN SESSION AND NOTHING ELSE. No device, no
     * `DoctorDeviceAuthorization` and no WebAuthn credential is touched; the
     * service writes to none of those tables. The flash reports what actually
     * happened, because a doctor who held no active lease is a no-op and saying
     * otherwise would send an operator looking for a problem that was never
     * there.
     */
    public function releaseSession(ReleaseDoctorSessionRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->assertCapabilityArmed();
        $this->authorize('releaseSession', DoctorBranchCover::class);

        $released = $this->sessionReleases->releaseForDoctor(
            (int) $doctor->id,
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('rme.doctor-branch-locks.index')
            ->with('status', $released
                ? 'Sesi login dokter diakhiri. Dokter harus login ulang. Perangkat, otorisasi dan kredensial WebAuthn tidak dicabut.'
                : 'Tidak ada sesi aktif untuk dokter ini. Ruangan kerja tetap dibebaskan.');
    }

    /**
     * The capability gate for every action on this surface.
     *
     * @throws NotFoundHttpException
     */
    private function assertCapabilityArmed(): void
    {
        abort_unless($this->effectiveBranch->enabled(), 404);
    }

    /**
     * May this actor file on another doctor's behalf?
     *
     * The same permission the two policies use for `create`, read once here so
     * the IDOR boundary and the rendered form agree about who the subject is.
     */
    private function mayFileForOthers(?User $user): bool
    {
        return $user !== null && $user->can('manage_doctor_branch_locks');
    }

    /**
     * @throws ValidationException when a non-filer has no linked doctor record
     */
    private function resolveSubjectDoctorId(StoreDoctorBranchLockRequestRequest $request): int
    {
        $user = $request->user();

        if ($this->mayFileForOthers($user)) {
            $submitted = $request->validated('doctor_id');

            if ($submitted === null) {
                throw ValidationException::withMessages([
                    'doctor_id' => 'Pilih dokter yang akan dikunci ke sebuah cabang.',
                ]);
            }

            return (int) $submitted;
        }

        $doctor = $this->identities->resolveForUser($user);

        if ($doctor === null) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Akun dokter belum terhubung ke data dokter. '
                    .'Hubungi admin untuk menghubungkan user ke master dokter.',
            ]);
        }

        return (int) $doctor->id;
    }

    /**
     * RULING P11 — one live presence reading per subject doctor, computed once
     * and handed to the view.
     *
     * Branch and room travel with the flag because the warning has to be
     * specific: an approver about to evict a doctor should see that they are in
     * a consultation room right now. It carries NO device name, NO IP, NO user
     * agent and NO session id — one actor's device details must never reach
     * another actor's screen.
     *
     * @param  Collection<int, Doctor>  $subjects
     * @return array<int, array<string, mixed>>
     */
    private function presenceSnapshot(Collection $subjects): array
    {
        $snapshot = [];

        foreach ($subjects as $doctor) {
            $user = $doctor->user;

            if ($user === null) {
                $snapshot[(int) $doctor->id] = [
                    'online' => false,
                    'linked' => false,
                    'branch' => null,
                    'room' => null,
                ];

                continue;
            }

            $context = $this->presence->currentContextFor($user);

            $snapshot[(int) $doctor->id] = [
                'online' => $this->presence->isDoctorOnline($user),
                'linked' => true,
                'branch' => $context?->branch?->name,
                'room' => $context?->clinicRoom?->name,
            ];
        }

        return $snapshot;
    }

    /**
     * The cover that is in force right now for each locked doctor, so the
     * approver can see what a permanent transfer would collide with.
     *
     * @param  Collection<int, DoctorBranchLock>  $locks
     * @return array<int, DoctorBranchCover>
     */
    private function activeCoverSnapshot(Collection $locks, CarbonImmutable $now): array
    {
        $snapshot = [];

        foreach ($locks as $lock) {
            $cover = $this->covers->activeApprovedForDoctor((int) $lock->doctor_id, $now);

            if ($cover !== null) {
                $snapshot[(int) $lock->doctor_id] = $cover;
            }
        }

        return $snapshot;
    }

    /**
     * FINDING U3 — every lock that exists but has stopped applying.
     *
     * The predicate is NOT re-implemented here. Each row is put to the one
     * canonical resolver, and any answer that is not HOME or COVER means the
     * stored lock is not binding the doctor right now. A branch that lost
     * `is_active` or `is_rme_enabled` is the case this was written for; an
     * unlinked account and a governance account that also carries the Doctor
     * role are surfaced by the same reading because they have the same
     * consequence — a lock row on file that decides nothing.
     *
     * @param  Collection<int, DoctorBranchLock>  $locks
     * @return array<int, array<string, mixed>>
     */
    private function degradedLockRows(Collection $locks, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($locks as $lock) {
            $user = $lock->doctor?->user;

            // A doctor record with no account is UNLINKED, and the resolver
            // cannot say so — it takes a User and answers `not_applicable` for
            // null before it ever reaches the identity lookup. Naming it here
            // keeps the sentence on screen true; a wrong explanation is worse
            // than none, because it sends an operator to the wrong remedy.
            $state = $user === null
                ? DoctorEffectiveBranch::unlinked()
                : $this->effectiveBranch->resolve($user, $now);

            if ($state->isLocked()) {
                continue;
            }

            $rows[] = [
                'doctor_id' => (int) $lock->doctor_id,
                'doctor_name' => $lock->doctor?->name,
                'home_branch_name' => $lock->homeBranch?->name,
                'home_branch_id' => $lock->home_branch_id === null ? null : (int) $lock->home_branch_id,
                'source' => $state->source(),
                'reason_code' => $state->degradedReason() ?? $state->source(),
                'reason_label' => $this->degradationLabel($state),
            ];
        }

        return $rows;
    }

    /**
     * The Indonesian sentence for a reason code, resolved here so the view has
     * no branching in it. The CODE is rendered beside the sentence because an
     * operator opening a support ticket needs the value an engineer will grep.
     */
    private function degradationLabel(DoctorEffectiveBranch $state): string
    {
        return match ($state->degradedReason()) {
            DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED => 'Cabang tetap dokter bukan lagi cabang RME aktif, sehingga kunci cabang berhenti berlaku dan dokter kembali ke perilaku lama.',
            DoctorEffectiveBranch::REASON_COVER_BRANCH_NOT_RME_ENABLED => 'Cabang cover bukan lagi cabang RME aktif, sehingga kunci cabang berhenti berlaku dan dokter kembali ke perilaku lama.',
            default => match ($state->source()) {
                DoctorEffectiveBranch::SOURCE_UNLINKED => 'Akun dokter belum terhubung ke data dokter, sehingga kunci cabang tidak mengikat siapa pun.',
                DoctorEffectiveBranch::SOURCE_NOT_APPLICABLE => 'Akun ini tidak dikunci cabang (akun tata kelola atau bukan akun dokter), sehingga baris kunci ini tidak berlaku.',
                default => 'Kunci cabang tersimpan tetapi tidak sedang mengikat dokter ini.',
            },
        };
    }

    /**
     * The DERIVED lifecycle word for each cover — Terjadwal / Sedang Berlaku /
     * Sudah Berakhir — resolved against the ONE instant this request is using.
     *
     * ACTIVE and EXPIRED are computed from approval plus timestamps and are
     * never persisted, so there is no column to render and nothing a delayed
     * job could leave stale. Resolved here rather than in the view so the
     * template carries no branching.
     *
     * @param  Collection<int, DoctorBranchCover>  $covers
     * @return array<int, string>
     */
    private function coverStateLabels(Collection $covers, CarbonImmutable $now): array
    {
        $labels = [];

        foreach ($covers as $cover) {
            $labels[(int) $cover->id] = DoctorBranchCoverState::label($cover->state($now));
        }

        return $labels;
    }

    /**
     * Home branch name per doctor, so the cover form can show what the cover
     * would temporarily override.
     *
     * @return array<int, string|null>
     */
    private function homeBranchNames(): array
    {
        $names = [];

        foreach ($this->locks->withDoctorAndBranch() as $lock) {
            $names[(int) $lock->doctor_id] = $lock->homeBranch?->name;
        }

        return $names;
    }
}
