<?php

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — framework values that depend on the
 * application key, and what retiring the previous key does to them.
 *
 * The rotation keeps the old key in APP_PREVIOUS_KEYS until every stored value
 * has been rewritten; the retire step then removes it. Two kinds of value the
 * database command cannot rewrite still carry the old key between those steps:
 *
 *   encrypted cookies (the session cookie among them) — EncryptCookies decrypts
 *   with every configured key and re-encrypts with the current key on the next
 *   response, so an active browser moves to the new key by itself;
 *
 *   signed URLs (the email verification link) — the URL generator accepts a
 *   signature made with any configured key.
 *
 * These tests pin both halves: with the old key still a previous key, an old
 * cookie and an old signed link keep working; once it is retired they are
 * rejected cleanly — the cookie reads as absent, the signature as invalid — and
 * nothing throws. Retirement therefore logs out a browser that has not been seen
 * since the rotation and expires old links; it never produces a 500.
 */

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

function afdKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function afdUseKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

/** A cookie value exactly as EncryptCookies writes it under the key configured now. */
function afdCookie(string $name, string $plain): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$plain, false);
}

/** The value the application sees for $name after EncryptCookies has run on the request. */
function afdReadThroughMiddleware(string $name, string $raw): ?string
{
    $request = Request::create('/probe', 'GET', [], [$name => $raw]);
    $seen = null;

    app(EncryptCookies::class)->handle($request, function (Request $r) use ($name, &$seen) {
        $seen = $r->cookies->get($name);

        return new Response('ok');
    });

    return $seen;
}

function afdSignedUrl(): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 7, 'hash' => sha1('probe@example.test')]);
}

function afdSignatureValid(string $url): bool
{
    return URL::hasValidSignature(Request::create($url));
}

it('reads an encrypted cookie written under the previous key until that key is retired', function () {
    $keyA = afdKey();
    afdUseKeys($keyA);
    $raw = afdCookie('dms_session_probe', 'session-id-123');

    // Control: under the key that wrote it.
    expect(afdReadThroughMiddleware('dms_session_probe', $raw))->toBe('session-id-123');

    // Rotated: A is a previous key — the browser stays signed in.
    $keyB = afdKey();
    afdUseKeys($keyB, [$keyA]);
    expect(afdReadThroughMiddleware('dms_session_probe', $raw))->toBe('session-id-123');

    // Retired: A is gone — the cookie reads as absent, and nothing throws.
    afdUseKeys($keyB);
    expect(afdReadThroughMiddleware('dms_session_probe', $raw))->toBeNull();
});

it('re-encrypts an old cookie with the current key on the next response', function () {
    $keyA = afdKey();
    afdUseKeys($keyA);
    $raw = afdCookie('dms_probe', 'value-1');

    $keyB = afdKey();
    afdUseKeys($keyB, [$keyA]);

    $request = Request::create('/probe', 'GET', [], ['dms_probe' => $raw]);
    $response = app(EncryptCookies::class)->handle($request, function (Request $r) {
        $out = new Response('ok');
        $out->headers->setCookie(cookie('dms_probe', (string) $r->cookies->get('dms_probe')));

        return $out;
    });

    $reissued = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'dms_probe');
    expect($reissued)->not->toBeNull();

    // The reissued value survives retirement of A: it was written with B.
    afdUseKeys($keyB);
    expect(afdReadThroughMiddleware('dms_probe', (string) $reissued->getValue()))->toBe('value-1');
});

it('accepts a signed link made with the previous key until that key is retired', function () {
    $keyA = afdKey();
    afdUseKeys($keyA);
    $url = afdSignedUrl();
    expect(afdSignatureValid($url))->toBeTrue();

    $keyB = afdKey();
    afdUseKeys($keyB, [$keyA]);
    expect(afdSignatureValid($url))->toBeTrue();

    afdUseKeys($keyB);
    expect(afdSignatureValid($url))->toBeFalse();

    // A link issued after the rotation is unaffected by retirement.
    expect(afdSignatureValid(afdSignedUrl()))->toBeTrue();
});

it('signs new links and cookies with the current key only', function () {
    $keyA = afdKey();
    $keyB = afdKey();
    afdUseKeys($keyB, [$keyA]);

    $url = afdSignedUrl();
    $raw = afdCookie('dms_probe', 'value-2');

    // Neither verifies under A alone: nothing new depends on the previous key.
    afdUseKeys($keyA);
    expect(afdSignatureValid($url))->toBeFalse()
        ->and(afdReadThroughMiddleware('dms_probe', $raw))->toBeNull();
});
