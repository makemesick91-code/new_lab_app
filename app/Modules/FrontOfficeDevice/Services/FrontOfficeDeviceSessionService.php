<?php

namespace App\Modules\FrontOfficeDevice\Services;

use App\Models\User;
use App\Modules\FrontOfficeDevice\Support\FrontOfficeDeviceLockDecision;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — record the refusal, then take the
 * session away.
 *
 * ORDER MATTERS AND IS NOT INCIDENTAL.
 *
 * The audit row is written FIRST, while the user is still readable; the session
 * is destroyed SECOND. Reversing the two loses the actor on exactly the events
 * an operator most needs attributed — a refused login. The online context is
 * marked offline before logout for the same reason the logout controller does
 * it: after `Auth::logout()` the user is no longer available to mark.
 *
 * A DENIED LOGIN LEAVES NO SESSION BEHIND, EVER.
 *
 * `invalidate()` tears the session down rather than merely redirecting, so a
 * refused front-desk login cannot leave a privileged session that a hidden menu
 * is the only thing standing in front of. That is the whole difference between
 * a lock and a decoration.
 */
class FrontOfficeDeviceSessionService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly FrontOfficeBranchDeviceLockService $lock,
    ) {}

    /**
     * Audit a refusal. Safe fields only: ids, codes and a reason code.
     *
     * Never a password, a credential, a token, a cookie, a session id, a
     * User-Agent or any biometric material — none of which is needed to explain
     * why the login was refused, and all of which would make the audit trail
     * itself a liability.
     */
    public function auditDenial(?User $user, FrontOfficeDeviceLockDecision $decision): void
    {
        if ($user === null) {
            return;
        }

        $this->auditLogs->log(
            'users',
            (int) $user->id,
            'FRONT_OFFICE_DEVICE_BRANCH_LOGIN_DENIED',
            null,
            $decision->auditContext(),
            $user,
        );
    }

    /**
     * Tear down a session that must not survive the refusal.
     */
    public function invalidate(Request $request, ?User $user): void
    {
        if ($user !== null) {
            app(UserOnlineContextService::class)->markOffline($user);
        }

        $this->lock->forgetBinding($request);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
