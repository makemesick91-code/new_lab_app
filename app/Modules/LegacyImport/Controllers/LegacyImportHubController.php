<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyImport\Services\LegacyImportHubService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — the read-only landing page for legacy imports.
 *
 * THIN BY CONSTRUCTION. Resolve the actor, ask the service, render. Every
 * number, every status and every branch on the page is decided server-side in
 * {@see LegacyImportHubService}; there is no query, no branch resolution and no
 * authorization arithmetic here.
 *
 * DEFENCE IN DEPTH, NOT DEFENCE IN THE ROUTE FILE. The route already carries the
 * permission middleware. This controller re-checks reachability anyway, because
 * a route file is edited far more often than a controller and a middleware list
 * that silently loses an entry must not silently open a page.
 */
class LegacyImportHubController extends Controller
{
    public function __construct(
        private readonly LegacyImportHubService $hub,
    ) {}

    public function index(Request $request): View
    {
        if (! $this->hub->enabled()) {
            // The hub page is a convenience over three capabilities that each
            // keep their own route. Turning it off removes the convenience, so
            // the honest response is "this page does not exist" rather than a
            // 403, which would imply the actor lacked authority.
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($this->hub->isReachableBy($user), 403);

        return view('settings.legacy-imports.index', [
            'overview' => $this->hub->overview($user),
        ]);
    }
}
