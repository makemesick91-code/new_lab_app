<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        // FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — role-aware, authorization-safe
        // landing (never an unconditional forbidden dashboard).
        $redirectTo = fn (): RedirectResponse => redirect()->to(
            ($target = app(PostAuthenticationRedirectService::class)->resolve($request))
                .(str_contains($target, '?') ? '' : '?verified=1')
        );

        if ($request->user()->hasVerifiedEmail()) {
            return $redirectTo();
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return $redirectTo();
    }
}
