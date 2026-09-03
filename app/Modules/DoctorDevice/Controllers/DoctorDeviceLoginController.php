<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DoctorDevice\Services\DoctorDeviceSessionService;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — ticket redemption.
 *
 * The one place a device-bound doctor session comes into existence. The Clinic
 * App has already proved its key, supplied credentials and been told the pair is
 * ACTIVE; the WebView then opens this URL so the cookie lands in its own jar.
 *
 * It is a GET because a WebView navigation is a GET. That is safe here for
 * reasons that have to hold together:
 *  - the ticket is single-use and claimed under a row lock, so a prefetch or a
 *    retry cannot produce two sessions;
 *  - it lives for seconds;
 *  - it is bound to user, doctor, device AND authorization, all re-asserted at
 *    redemption;
 *  - it can only exist if enforcement is on, and redemption refuses outright
 *    when it is off.
 *
 * On failure the doctor is sent back to the login screen with one generic
 * message. Distinguishing "expired" from "revoked" here would tell an attacker
 * holding a stolen ticket which one they have.
 */
class DoctorDeviceLoginController extends Controller
{
    public function __construct(
        private readonly DoctorDeviceSessionService $sessions,
        private readonly PostAuthenticationRedirectService $redirects,
    ) {}

    public function redeem(Request $request, string $ticket): RedirectResponse
    {
        try {
            $this->sessions->redeem($ticket, $request);
        } catch (ValidationException) {
            return redirect()->route('login')->withErrors([
                'email' => 'Sesi perangkat tidak dapat dibuat. Silakan masuk kembali melalui aplikasi klinik.',
            ]);
        }

        // The same role-aware landing decision the ordinary login uses, so an
        // app login and a browser login can never disagree about where a user
        // belongs.
        return redirect()->to($this->redirects->resolve($request));
    }
}
