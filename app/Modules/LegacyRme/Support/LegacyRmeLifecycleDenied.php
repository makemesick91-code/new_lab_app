<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * LEGACY-RME-OPS-CLI-1 — an authorization refusal that remembers WHICH gate
 * refused.
 *
 * The lifecycle service has two authorization gates: the named permission the
 * equivalent HTTP route declares as middleware, and LegacyRmeImportPolicy. Both
 * legitimately raise an AuthorizationException, and over HTTP that is all a
 * caller needs — 403 either way.
 *
 * An operator scripting against `--json` needs more: "you do not hold
 * publish_legacy_rme_imports" and "this import is in the wrong state for
 * publishing" have completely different remedies, and a single opaque 403 sends
 * them to the wrong person. This subclass carries the stable refusal code so the
 * command line can say which, while still rendering as an ordinary 403 in the
 * browser — the HTTP contract is unchanged.
 */
class LegacyRmeLifecycleDenied extends AuthorizationException
{
    public readonly string $refusalCode;

    public function __construct(string $refusalCode, string $message)
    {
        parent::__construct($message);

        $this->refusalCode = $refusalCode;
    }
}
