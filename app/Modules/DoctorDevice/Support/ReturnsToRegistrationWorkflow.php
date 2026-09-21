<?php

namespace App\Modules\DoctorDevice\Support;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — send the operator back to the
 * step they came from.
 *
 * The guided workflow deliberately REUSES the existing mutation routes rather
 * than duplicating security-sensitive actions, and those routes redirect to the
 * device registry when they are done. Without this, every step of a seven-step
 * wizard would eject the operator onto a different screen.
 *
 * NOT AN OPEN REDIRECT, and shaped that way on purpose. The request carries a
 * BOOLEAN, never a URL, never a route name, never a path. The destination is
 * built here from a hardcoded route name and the device the mutation already
 * operated on, so the worst a forged value can do is send its own sender to a
 * page they were already permitted to open. A `?redirect_to=` parameter on a
 * device-approval endpoint would have been the other thing.
 *
 * It runs AFTER the mutation and after its authorization, so it can neither
 * grant nor skip anything.
 */
trait ReturnsToRegistrationWorkflow
{
    public const WORKFLOW_FLAG = 'registration_workflow';

    /**
     * The named route to redirect to, or null when the caller did not come
     * from the guided workflow and its own default should stand.
     */
    protected function workflowReturnRoute(Request $request, DoctorDevice $device, string $step): ?string
    {
        if (! $request->boolean(self::WORKFLOW_FLAG)) {
            return null;
        }

        $route = 'settings.doctor-device-registration.'.$step;

        return Route::has($route) ? $route : null;
    }
}
