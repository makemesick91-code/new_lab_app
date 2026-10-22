<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\ActiveAccountResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — authentication is not a
 * licence that outlives the account (rule 200).
 *
 * WHAT IT CLOSES. The session guard stores only a user id. Before this sprint
 * nothing compared that id with the account's CURRENT status, so a session
 * opened before an administrator switched the account off kept working on every
 * policy-only surface (rule 198 §30a, rule 199 §24/§27c), and a remember-me
 * cookie restored a deactivated account outright. The domain guards already
 * refused such an account for visits and billing; this closes everything else.
 *
 * WHY THE WHOLE `web` GROUP. A deactivated account must stop EVERYWHERE, and an
 * enumerated list of protected routes is a list somebody eventually forgets to
 * extend. For a guest it costs nothing: there is no authenticated account, so
 * the first branch returns. For an authenticated request it is one indexed
 * primary-key read. Guest pages, login, health endpoints and the stateless
 * device API (not in this group) are untouched.
 *
 * WHERE IT SITS. Appended to `web`, so it runs after StartSession (it needs the
 * session and the user) and — deliberately — immediately AFTER
 * EnsureDoctorSessionLease and BEFORE TouchOnlineContextLastSeen /
 * EnsureRmeOnlineContext: the request that ends a deactivated doctor's session
 * must not first refresh that doctor's presence (a ghost holding a clinic room),
 * nor be redirected to the branch selector instead of being stopped.
 *
 * WHAT IT DOES ON REFUSAL — exactly what a logout does, in the same order as
 * AuthenticatedSessionController::destroy(): drop the presence (frees the room),
 * release this session's doctor lease, then log out, invalidate the session and
 * rotate the CSRF token. `logout()` also cycles the remember token, which kills
 * every remember-me cookie the deactivated account still has on any device.
 * One audit row per terminated session; the session is gone afterwards, so the
 * next request is a guest and writes nothing.
 *
 * Soft-deleted accounts never reach the refusal branch: the Eloquent provider's
 * SoftDeletes scope already resolves them to no user at all.
 */
class EnsureAccountIsActive
{
    public const AUDIT_ENTITY = 'users';

    public const AUDIT_ACTION = 'INACTIVE_ACCOUNT_SESSION_TERMINATED';

    public function __construct(
        private readonly ActiveAccountResolver $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user === null || $this->accounts->isUsable($user)) {
            return $next($request);
        }

        $this->endSession($request, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => ActiveAccountResolver::INACTIVE_MESSAGE], 401);
        }

        return redirect()->route('login')
            ->withErrors(['email' => ActiveAccountResolver::INACTIVE_MESSAGE]);
    }

    // NOT named terminate(): the HTTP kernel calls a middleware's terminate()
    // after EVERY response (terminable middleware), whatever its visibility.
    private function endSession(Request $request, mixed $user): void
    {
        try {
            if ($user instanceof User) {
                app(UserOnlineContextService::class)->markOffline($user);

                app(DoctorSessionLeaseService::class)->releaseCurrent(
                    $request,
                    $user,
                    DoctorSessionLease::RELEASE_ACCOUNT_INACTIVE,
                );

                // IDs and a reason code only: no name, no e-mail, no session id.
                app(AuditLogService::class)->log(
                    self::AUDIT_ENTITY,
                    (int) $user->getKey(),
                    self::AUDIT_ACTION,
                    null,
                    ['reason' => 'account_inactive', 'surface' => 'web'],
                    $user,
                );
            }
        } catch (\Throwable $e) {
            // The refusal is the security outcome. A failure in any bookkeeping
            // step above is reported, but must never turn the refusal into a
            // 500 or leave the deactivated session alive.
            report($e);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
