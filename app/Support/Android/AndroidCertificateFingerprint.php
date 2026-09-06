<?php

namespace App\Support\Android;

/**
 * PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1.
 *
 * The single semantics for "is this a production signing certificate
 * fingerprint, and is it the same one?".
 *
 * It exists because the question previously had three answers in three files.
 * `AndroidReleaseGovernanceScanner::isCertificateFingerprint()` used a
 * case-insensitive regex, `AndroidReleaseArtifactVerifier::signerChecks()` kept
 * its own inline copy of that regex, and the custody state machine used
 * `is_string($v) && $v !== ''`. A fourth variant — the same regex WITHOUT the
 * case-insensitive flag — decided whether the recorded certificate counted.
 *
 * That is not a tidiness complaint. Security review had already found what the
 * drift costs: with the pin set to `'TBD'` the scanner printed
 * PRODUCTION_CERTIFICATE_PINNED=true while the verifier rejected the same value
 * and failed closed, so the report asserted the opposite of the control it
 * exists to describe. The fix at the time was to align two of the predicates by
 * hand, which holds exactly until the next edit to either one.
 *
 * REPORTING AND ENFORCEMENT NOW ASK THE SAME OBJECT. `isValid()` is the only
 * definition of a fingerprint, and both callers use it, so they cannot disagree
 * without this file changing.
 *
 * TWO PREDICATES, DELIBERATELY. `isPresent()` is NOT a weaker `isValid()` and
 * collapsing them would be a fail-open regression. They answer different
 * questions:
 *
 *   isPresent()  Does this field carry a CLAIM? The custody lifecycle needs
 *                this one. A pin of `'TBD'` written while no key is provisioned
 *                must still trip "a certificate is pinned while no key is
 *                claimed provisioned" — under a validity-only predicate that
 *                junk would be invisible and the ordering rule would pass.
 *
 *   isValid()    Is the claim a fingerprint? Reporting and install-time
 *                enforcement need this one, because a value that is not a
 *                fingerprint cannot authenticate anything.
 *
 * The fingerprint is PUBLIC. It is derived from the certificate, which ships
 * inside every signed APK, so nothing here reads, needs or discloses a private
 * key, a keystore or a passphrase.
 */
final class AndroidCertificateFingerprint
{
    /**
     * Exactly 64 contiguous hex characters, in either case.
     *
     * Colon-separated input — which is what `keytool -list` prints, so it WILL
     * be pasted one day — is rejected rather than normalized. A normalizer that
     * strips separators also quietly accepts values a reviewer would reject,
     * and widening accepted input is the opposite of what a trust anchor wants.
     * It fails closed and the operator reformats.
     *
     * The `D` modifier is load-bearing and was MISSING from both regexes this
     * class replaced. Without it PHP's `$` also matches immediately before a
     * trailing newline, so `"<64 hex>\n"` — which is exactly what a paste from
     * a file or from `keytool` output produces — passed as a valid fingerprint.
     *
     * The consequence was not a harmless whitespace nit. `signer_trust_anchor`
     * would report the anchor ARMED, and then the byte comparison against a
     * clean signer fingerprint would fail on length and print "this APK was
     * signed by a different key — do not install it". A newline in config would
     * have been reported to an operator as a SUBSTITUTED SIGNING KEY, which is
     * the single message in this whole subsystem most likely to be acted on
     * hardest and the hardest one to walk back. `D` makes `$` mean the end of
     * the string, so the value fails validation where it is wrong.
     */
    private const PATTERN = '/^[0-9a-f]{64}$/iD';

    /**
     * Whether the field carries a claim at all — not whether the claim is well
     * formed. See the class docblock: the lifecycle rules need junk to COUNT so
     * that junk cannot be written ahead of the key it implies.
     */
    public static function isPresent(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /** Whether the value is a certificate SHA-256 fingerprint. */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The canonical lower-case form, or null when the value is not a
     * fingerprint.
     *
     * Null rather than a best-effort string: a caller that receives something
     * back for `'TBD'` will compare it against something, and comparing junk is
     * how a substituted artifact gets a verdict instead of a refusal.
     */
    public static function normalize(mixed $value): ?string
    {
        return self::isValid($value) ? strtolower((string) $value) : null;
    }

    /**
     * Whether two values denote the SAME certificate.
     *
     * Case-insensitive, because case is representation and not identity —
     * `keytool` prints upper case and a correct pin differing only in case must
     * never be reported as a substituted key, which is the one message an
     * operator would act on hardest.
     *
     * Invalid on either side is false, never a lenient match: "could not tell"
     * is not "matches". Both operands are normalized to the same length before
     * `hash_equals`, so there is no prefix, suffix or substring path to a true.
     */
    public static function matches(mixed $a, mixed $b): bool
    {
        $left = self::normalize($a);
        $right = self::normalize($b);

        if ($left === null || $right === null) {
            return false;
        }

        return hash_equals($left, $right);
    }
}
