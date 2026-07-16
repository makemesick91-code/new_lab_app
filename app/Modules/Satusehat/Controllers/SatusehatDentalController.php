<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Services\SatusehatDentalProfileAuditService;
use App\Modules\Satusehat\Services\SatusehatProductionReadinessService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * Read-only dental coverage + production-readiness governance pages. Thin
 * controller — all logic in services. No external request; PII-free views.
 */
class SatusehatDentalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatDentalProfileAuditService $audit,
        private readonly SatusehatProductionReadinessService $readiness,
    ) {}

    public function coverage(): View
    {
        return view('satusehat.dental.coverage', [
            'audit' => $this->audit->audit(),
            'coverage' => (array) config('satusehat_dental.coverage'),
            'profile' => (array) config('satusehat_dental.official_profile'),
            'environment' => (string) config('satusehat.environment'),
        ]);
    }

    public function productionReadiness(): View
    {
        return view('satusehat.dental.production-readiness', [
            'report' => $this->readiness->report(),
            'satusehat2Watch' => ! (bool) config('satusehat.sandbox_verified'),
        ]);
    }
}
