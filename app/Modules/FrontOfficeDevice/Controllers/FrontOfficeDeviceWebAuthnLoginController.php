<?php

namespace App\Modules\FrontOfficeDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DoctorDevice\Requests\DoctorDeviceWebAuthnAssertionRequest;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeDeviceWebAuthnLoginService;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — the "touch the front-desk tablet"
 * step.
 *
 * THIN BY CONSTRUCTION. Every decision belongs to
 * `FrontOfficeDeviceWebAuthnLoginService`; this class resolves the pending
 * account, hands the payload over, and redirects. It holds no cohort condition
 * and no branch comparison of its own — scattering those across controllers is
 * precisely what the single decision service exists to prevent.
 *
 * THESE ROUTES ARE UNAUTHENTICATED, WHICH IS THE POINT.
 *
 * The privileged session was destroyed when the login was denied. What a visitor
 * holds here is a short-lived marker naming the account whose password they
 * already typed — not a credential and not a session. It lets them ask for a
 * challenge; it grants no access to anything. Completing the ceremony still
 * requires a private key held by an approved tablet in the right branch, and the
 * credential, the device, its approval and its branch are all re-asserted
 * server-side before any session is opened.
 *
 * Reuses `DoctorDeviceWebAuthnAssertionRequest` deliberately: it validates the
 * SHAPE of a WebAuthn assertion payload, which is a property of the WebAuthn
 * specification rather than of the doctor programme. A second copy would be a
 * second thing to keep correct.
 */
class FrontOfficeDeviceWebAuthnLoginController extends Controller
{
    public function __construct(
        private readonly FrontOfficeDeviceWebAuthnLoginService $logins,
        private readonly FrontOfficeBranchDeviceLockService $lock,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->logins->pendingUser($request);

        // No marker, an expired marker, or the capability switched off while
        // somebody stood at the sensor. All three go back to the login form
        // rather than to a ceremony that cannot complete.
        if ($user === null || ! $this->lock->enforcementEnabled()) {
            return redirect()->route('login');
        }

        return view('auth.front-office-device-webauthn', [
            'userName' => $user->name,
        ]);
    }

    /** Options for `navigator.credentials.get()`. */
    public function options(Request $request): JsonResponse
    {
        $user = $this->logins->pendingUser($request);

        if ($user === null) {
            return response()->json(['message' => 'Sesi login telah berakhir.'], 419);
        }

        $result = $this->logins->requestOptions($user, $request);

        $factory = new WebAuthnCeremonyFactory(WebAuthnRelyingParty::fromConfig());

        return response()->json(
            json_decode(
                $factory->serializer()->serialize($result['options'], 'json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )
        );
    }

    /** Verify the assertion and, only then, open the session. */
    public function store(DoctorDeviceWebAuthnAssertionRequest $request): RedirectResponse
    {
        $user = $this->logins->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'email' => 'Sesi login telah berakhir. Silakan masuk kembali.',
            ]);
        }

        $this->logins->completeLogin($user, $request, $request->credentialPayload());

        return redirect()->to(
            app(PostAuthenticationRedirectService::class)->resolve($request)
        );
    }
}
