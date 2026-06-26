<?php

namespace App\Modules\ClinicVisit\Middleware;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotfix Sprint 60.8 — RME room-assignment gate before doctor examination.
 *
 * Blocks doctor examination input (Rekam Medis & Odontogram routes) for any
 * active pre-examination visit that has not been placed into a treatment room
 * yet. This is the authoritative, controller-level enforcement of the gate —
 * hiding buttons in Blade is only a UX hint. Terminal and cashier_pending
 * visits are exempt (handled by ClinicVisit::requiresRoomBeforeExam()), so
 * post-examination editing introduced in Sprint 59 is never blocked.
 */
class EnsureVisitRoomAssigned
{
    public function handle(Request $request, Closure $next): Response
    {
        $visit = $this->resolveVisit($request);

        if ($visit instanceof ClinicVisit && $visit->requiresRoomBeforeExam()) {
            return redirect()
                ->route('rme.visits.show', $visit)
                ->with('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
        }

        return $next($request);
    }

    /**
     * Resolve the clinic visit from the route. Most exam routes bind
     * {clinicVisit} directly; the odontogram update/finalize routes bind only
     * {odontogram}, so we fall back to the odontogram's owning visit.
     */
    private function resolveVisit(Request $request): ?ClinicVisit
    {
        $visit = $request->route('clinicVisit');

        if ($visit instanceof ClinicVisit) {
            return $visit;
        }

        $odontogram = $request->route('odontogram');

        return $odontogram?->clinicVisit;
    }
}
