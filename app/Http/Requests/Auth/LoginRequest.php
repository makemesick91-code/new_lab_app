<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\AccessControl\ActiveAccountResolver;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 (rule 200) — a
        // deactivated account must not log in. The callback runs ONLY after the
        // password has been verified, so the specific "inactive" message is
        // shown to someone who has just proven they hold the account, never to
        // an anonymous guess: a wrong password, an unknown address and a
        // soft-deleted account all keep the generic failure. The decision is the
        // persisted row's, through the single shared definition of a usable
        // account. A refused attempt still counts against the rate limiter.
        $inactive = false;

        $authenticated = Auth::attemptWhen(
            $this->only('email', 'password'),
            function (User $user) use (&$inactive): bool {
                if (app(ActiveAccountResolver::class)->isUsable($user)) {
                    return true;
                }

                $inactive = true;

                return false;
            },
            $this->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => $inactive ? ActiveAccountResolver::INACTIVE_MESSAGE : trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
