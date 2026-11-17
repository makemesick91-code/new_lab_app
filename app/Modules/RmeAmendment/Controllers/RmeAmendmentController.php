<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Requests\RmeAmendmentActionRequest;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — the supervised
 * amendment pages: the request form of a final sheet or chart, the supervisor
 * queue, the detail page, cancel and reject. Thin: route permission →
 * RmeAmendmentPolicy → RmeAmendmentService, which re-decides everything inside
 * its transaction. Every page is `private, no-store` (route middleware).
 * Approve-and-apply and the version history pages are rule 240 §7 (M3.3 PR5).
 */
class RmeAmendmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeAmendmentService $amendments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RmeAmendment::class);

        $status = in_array($request->query('status'), RmeAmendment::STATUSES, true) ? (string) $request->query('status') : null;

        $rows = $this->amendments->queueFor($request->user(), $status);

        return view('rme.amendments.index', [
            'rows' => $rows,
            // A row shows the patient's identity and its link only when this
            // account may open it (the doctor-patient scope may narrow an approver).
            'viewable' => $this->amendments->viewableIds($request->user(), $rows->getCollection()),
            'counts' => $this->amendments->statusCountsFor($request->user()),
            'status' => $status,
        ]);
    }

    public function show(Request $request, RmeAmendment $amendment): View
    {
        $this->authorize('view', $amendment);

        return view('rme.amendments.show', $this->amendments->detailFor($amendment, $request->user()));
    }

    public function createForSheet(Request $request, MedicalRecord $medicalRecord): View
    {
        return $this->form($request, $medicalRecord);
    }

    public function createForChart(Request $request, Odontogram $odontogram): View
    {
        return $this->form($request, $odontogram);
    }

    public function storeForSheet(RmeAmendmentActionRequest $request, MedicalRecord $medicalRecord): RedirectResponse
    {
        return $this->store($request, $medicalRecord);
    }

    public function storeForChart(RmeAmendmentActionRequest $request, Odontogram $odontogram): RedirectResponse
    {
        return $this->store($request, $odontogram);
    }

    public function cancel(Request $request, RmeAmendment $amendment): RedirectResponse
    {
        $this->authorize('cancel', $amendment);

        $this->amendments->cancel($amendment, $request->user());

        return redirect()->route('rme.amendments.show', $amendment)
            ->with('success', 'Permintaan amandemen dibatalkan. Rekam medis tetap seperti semula.');
    }

    public function reject(RmeAmendmentActionRequest $request, RmeAmendment $amendment): RedirectResponse
    {
        $this->authorize('reject', $amendment);

        $this->amendments->reject($amendment, $request->user(), (string) $request->validated('decision_reason'));

        return redirect()->route('rme.amendments.show', $amendment)
            ->with('success', 'Permintaan amandemen ditolak. Rekam medis tetap seperti semula.');
    }

    /** Approve AND apply (rule 240 §7) — one transaction in the service. */
    public function approve(RmeAmendmentActionRequest $request, RmeAmendment $amendment): RedirectResponse
    {
        $this->authorize('approve', $amendment);

        $applied = $this->amendments->approve($amendment, $request->user(), $request->validated('decision_reason'));

        return redirect()->route('rme.amendments.show', $amendment)
            ->with('success', 'Amandemen disetujui dan diterapkan sebagai versi '.$applied->applied_version.'. Rekam medis tetap final dan terkunci.');
    }

    public function sheetVersions(Request $request, MedicalRecord $medicalRecord): View
    {
        return view('rme.amendments.versions', $this->amendments->versionHistoryFor($request->user(), $medicalRecord));
    }

    public function chartVersions(Request $request, Odontogram $odontogram): View
    {
        return view('rme.amendments.versions', $this->amendments->versionHistoryFor($request->user(), $odontogram));
    }

    public function sheetVersion(Request $request, MedicalRecord $medicalRecord, int $version): View
    {
        return view('rme.amendments.version', $this->amendments->versionDetailFor($request->user(), $medicalRecord, $version));
    }

    public function chartVersion(Request $request, Odontogram $odontogram, int $version): View
    {
        return view('rme.amendments.version', $this->amendments->versionDetailFor($request->user(), $odontogram, $version));
    }

    private function form(Request $request, MedicalRecord|Odontogram $subject): View
    {
        $this->authorize('request', [RmeAmendment::class, $subject]);
        // Binds Super Admin too (Gate::before skips the policy): the same answer
        // the service would give on submit, including "one already pending".
        $this->amendments->assertMayRequest($subject, $request->user());

        return view('rme.amendments.create', [
            'subject' => $subject,
            'isChart' => $subject instanceof Odontogram,
            'current' => $this->amendments->requestFormFor($subject),
        ]);
    }

    private function store(RmeAmendmentActionRequest $request, MedicalRecord|Odontogram $subject): RedirectResponse
    {
        $this->authorize('request', [RmeAmendment::class, $subject]);
        $this->amendments->assertMayRequest($subject, $request->user());

        $amendment = $this->amendments->request($subject, $request->user(), (string) $request->validated('reason'), $request->proposal());

        return redirect()->route('rme.amendments.show', $amendment)
            ->with('success', 'Permintaan amandemen diajukan dan menunggu persetujuan Supervisor RME. Rekam medis belum berubah.');
    }
}
