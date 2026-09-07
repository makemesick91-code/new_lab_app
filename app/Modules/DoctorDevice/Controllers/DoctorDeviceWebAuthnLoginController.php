<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DoctorDevice\Requests\DoctorDeviceWebAuthnAssertionRequest;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — the second step of a doctor's browser login.
 *
 * WHY EVERY ACTION RE-RESOLVES THE PENDING USER
 *
 * There is no authenticated user here — that is the point. The password step
 * has succeeded and been deliberately torn down, leaving only a short-lived
 * marker naming the account. So each action asks the service who that account
 * is, and a missing or expired marker sends the visitor back to the login form
 * rather than being treated as an error.
 *
 * That marker is not a credential. Holding it lets someone request a challenge
 * for an account whose password they already typed; it does not let them
 * complete an assertion, because the assertion needs a private key held by an
 * approved device.
 */
class DoctorDeviceWebAuthnLoginController extends Controller
{
    public function __construct(private readonly DoctorDeviceWebAuthnLoginService $logins) {}

    /** The "touch the tablet" page. */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->logins->pendingUser($request);

        if ($user === null || ! $this->logins->enabled()) {
            return redirect()->route('login');
        }

        return view('auth.doctor-device-webauthn', [
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
