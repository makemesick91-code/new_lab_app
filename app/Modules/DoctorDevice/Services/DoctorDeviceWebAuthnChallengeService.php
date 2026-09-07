<?php

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — issuing and burning ceremony nonces.
 *
 * THE BURN HAPPENS BEFORE THE VERIFICATION, AND IN ITS OWN TRANSACTION
 *
 * This is the single most important property in the file, and it is not
 * obvious, so it is worth stating plainly: `claim()` marks the challenge
 * consumed and COMMITS, and only then does the caller verify a signature
 * against it.
 *
 * The tempting alternative — burn inside the same transaction as the
 * verification, so a failed attempt "doesn't waste" a nonce — is a real
 * vulnerability, and this codebase has already shipped a fix for exactly it on
 * the Android path. A rolled-back burn leaves the nonce live, which converts a
 * one-shot challenge into an oracle an attacker can hammer indefinitely with
 * forged signatures until one is accepted.
 *
 * So a failed ceremony costs the user one extra tap. That is the correct trade.
 *
 * WHAT A CHALLENGE IS BOUND TO
 *
 * A random value alone is not enough. Each nonce carries the ceremony it was
 * issued for, the session that asked for it, and (for an assertion) the user
 * who had just passed the password step. All three are re-checked at claim
 * time, so a challenge cannot be moved between ceremonies, between browsers, or
 * between accounts.
 */
class DoctorDeviceWebAuthnChallengeService
{
    /** Session key holding the per-browser ceremony token. See sessionHash(). */
    public const SESSION_CEREMONY_TOKEN = 'doctor_device_webauthn.ceremony_token';

    /**
     * Issue a fresh nonce.
     *
     * @param  string  $ceremony  one of DoctorDeviceWebAuthnChallenge::CEREMONIES
     */
    public function issue(
        string $ceremony,
        Request $request,
        ?int $userId = null,
        ?int $doctorDeviceId = null,
    ): DoctorDeviceWebAuthnChallenge {
        $bytes = max(32, (int) config('webauthn.ceremony.challenge_bytes', 32));
        $ttl = max(30, (int) config('webauthn.ceremony.challenge_ttl_seconds', 120));

        return DoctorDeviceWebAuthnChallenge::query()->create([
            'uuid' => (string) Str::uuid(),
            'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes($bytes)),
            'ceremony' => $ceremony,
            'user_id' => $userId,
            'doctor_device_id' => $doctorDeviceId,
            'session_id_hash' => $this->sessionHash($request, mintIfMissing: true),
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }

    /**
     * Claim a challenge for use, or fail.
     *
     * Returns the challenge only when it was THIS call that burned it. A
     * challenge that was already consumed, has expired, belongs to another
     * ceremony, another session or another user comes back as null — the caller
     * cannot tell those apart, and does not need to.
     */
    public function claim(
        string $challenge,
        string $ceremony,
        Request $request,
        ?int $expectedUserId = null,
    ): ?DoctorDeviceWebAuthnChallenge {
        $challenge = trim($challenge);

        if ($challenge === '' || strlen($challenge) > 255) {
            return null;
        }

        /** @var array{model: DoctorDeviceWebAuthnChallenge, claimed: bool}|null $claim */
        $claim = DB::transaction(function () use ($challenge) {
            $model = DoctorDeviceWebAuthnChallenge::query()
                ->lockForUpdate()
                ->where('challenge', $challenge)
                ->first();

            if ($model === null) {
                return null;
            }

            if (! $model->isUsable()) {
                return ['model' => $model, 'claimed' => false];
            }

            $model->forceFill(['consumed_at' => now()])->save();

            return ['model' => $model, 'claimed' => true];
        });

        if ($claim === null || $claim['claimed'] !== true) {
            return null;
        }

        $model = $claim['model'];

        // Binding checks run AFTER the burn on purpose. A challenge presented
        // to the wrong ceremony has still been spent, so probing for a valid
        // (nonce, ceremony) pairing costs the attacker the nonce.
        if ($model->ceremony !== $ceremony) {
            return null;
        }

        if ($expectedUserId !== null && (int) $model->user_id !== $expectedUserId) {
            return null;
        }

        if ($model->session_id_hash !== null
            && $model->session_id_hash !== $this->sessionHash($request)) {
            return null;
        }

        return $model;
    }

    /**
     * A stable per-session ceremony token, hashed.
     *
     * WHY NOT THE SESSION ID
     *
     * The obvious binding is `session()->getId()`, and it is wrong. A session id
     * rotates for reasons that have nothing to do with an attack — Laravel
     * regenerates it on login as fixation defence, and this feature's own login
     * path regenerates twice before the ceremony even starts. Binding to it
     * would mean a rotation between issuing a challenge and completing it makes
     * the ceremony fail, and the symptom of that is a doctor who cannot reach
     * their patients. A control whose failure mode is a clinical outage needs a
     * better reason than "it was the first identifier to hand".
     *
     * So the binding is a random token kept in the SESSION DATA. Data survives
     * `regenerate()` — which migrates it — and is destroyed by `invalidate()`
     * and `flush()`. That is exactly the distinction we want: a rotated id is
     * still the same browser, a flushed session is not.
     *
     * WHAT THIS BUYS, GIVEN THE CHALLENGE IS ALREADY BOUND TO A USER
     *
     * A signed assertion is a bearer artifact between being produced and the
     * nonce being burned. Without this, a payload captured from one browser
     * could be completed from another as long as the account matched. With it,
     * a captured payload is useless anywhere but the session that asked for it.
     *
     * Hashed rather than stored raw, so read access to this table does not
     * yield a set of live tokens.
     */
    private function sessionHash(Request $request, bool $mintIfMissing = false): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $token = $session->get(self::SESSION_CEREMONY_TOKEN);

        if (! is_string($token) || $token === '') {
            if (! $mintIfMissing) {
                // Claiming with no token at all: this is not the browser the
                // challenge was issued to.
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $session->put(self::SESSION_CEREMONY_TOKEN, $token);
        }

        return hash('sha256', $token);
    }
}
