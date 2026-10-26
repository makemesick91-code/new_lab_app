<?php

namespace App\Modules\DoctorDevice\Support;

use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — deciding whether a credential is confined to ONE
 * piece of hardware.
 *
 * THE MISTAKE THIS CLASS EXISTS TO PREVENT
 *
 * It is very easy to believe this:
 *
 *     authenticatorAttachment === 'platform'  =>  the key cannot leave this device
 *
 * It is false, and it has been false since synced passkeys shipped. A platform
 * authenticator on a modern Android tablet routinely creates a key that is
 * non-exportable from that tablet's hardware AND simultaneously present in the
 * signed-in Google account — and therefore on the owner's phone, laptop, and
 * any device they later sign into. "Platform" describes where the ceremony
 * happened. It says nothing about how many devices hold the key.
 *
 * For a clinic tablet that distinction is the entire feature. If a doctor's
 * personal phone can silently receive a working credential for the approved
 * clinic device, then "approved physical device" is not a security property,
 * it is a label.
 *
 * WHAT ACTUALLY ANSWERS THE QUESTION
 *
 * Two bits in the authenticator data:
 *
 *   BE (Backup Eligible) — may this credential ever be synced? Fixed at
 *                          creation, immutable for the credential's life.
 *   BS (Backup State)    — is it currently backed up?
 *
 * BE=0 is the only signal that a credential is single-device. It is an
 * authenticator's claim rather than a cryptographic proof — nothing in WebAuthn
 * can prove a negative about key custody — but it is the strongest signal the
 * platform defines, it is what the spec defines *for this question*, and the
 * library already refuses inconsistent combinations (BS=1 with BE=0) before we
 * see them.
 *
 * BS IS NOT USED FOR THE VERDICT, AND THAT IS DELIBERATE
 *
 * BS is mutable: a backup-eligible credential reports BS=0 until the moment it
 * syncs. Judging on BS would therefore PASS a syncable credential that simply
 * had not synced yet, and then silently keep passing it after it had. BE is the
 * durable property, so BE is the gate.
 *
 * SILENCE IS NOT A PASS
 *
 * An authenticator that reports no backup flags has not told us the credential
 * is device-bound. That is `unknown`, and under a policy that requires device
 * binding `unknown` is refused exactly like `backup_eligible`. Treating absent
 * evidence as good evidence is how a requirement quietly becomes optional.
 *
 * ── SECURITY-DOCTOR-WEBAUTHN-DEVICE-BOUND-FAIL-CLOSED-1 ─────────────────────
 *
 * THE REQUIREMENT ITSELF MUST FAIL CLOSED
 *
 * The config file used to read `(bool) env('WEBAUTHN_REQUIRE_DEVICE_BOUND',
 * true)`. Laravel's env() returns a present-but-empty variable as '' and maps
 * the literals `empty`/`(empty)` to '' and `null`/`(null)` to null — and PHP
 * casts all of those to FALSE. A blank line in the environment file therefore
 * switched the requirement OFF with no error and no log line. The runtime
 * reader had the same shape: `(bool) config(...)` read a null as "relaxed".
 *
 * So there is now exactly one parser for the environment value
 * (resolveRequirementFromEnv, called from the config file) and exactly one
 * reader of the resolved value (isRequired). Every consumer that honours the
 * policy — the two login services, the per-request gate, registration, the
 * readiness command, the live-proof report and the enrolment pages — goes
 * through them, and a structural test fails CI if anything reads the key
 * directly again. A few consumers (DoctorDeviceCredentialUsabilityPolicy,
 * DoctorDeviceIdentityProofPolicy, the estate report's eligibility) ignore the
 * setting and always demand device-bound; they can only ever disagree in the
 * stricter direction, by design.
 *
 * THE RELAXING VOCABULARY IS THE NARROWEST ONE
 *
 * Only a boolean `false` — what env() makes of an unquoted `false` or
 * `(false)`, in any case — the exact string `0` and the integer 0 relax the
 * requirement: the readings that already relaxed it before this fix. `off`,
 * `no`, a padded value and every other unrecognised value resolve to REQUIRED,
 * because `(bool) 'off'` was true and accepting them now would silently weaken
 * a deployment that relied on the old reading. That includes a literally
 * quoted `'false'` (e.g. from a container env-file that keeps quotes): env()
 * maps booleans BEFORE it strips quotes, so it arrives as the STRING `false`,
 * which `(bool)` read as true. A fix is never allowed to be a relaxation. Tightening spellings
 * (`true`, `1`, `on`, `yes`) are accepted generously; a spelling can only ever
 * be read as the safer of its possible meanings.
 *
 * THE SIGNED FLAGS MUST AGREE WITH THE REGISTRATION
 *
 * A registration with `attestation: none` carries no attestation signature, so
 * the BE bit recorded then is the client's word. The ASSERTION is different:
 * its authenticator data is covered by the credential's own signature. The
 * library verifies that signature and, if the signed BE bit differs from the
 * credential record, dispatches an event and carries on — it does not refuse.
 * W3C WebAuthn Level 3 §7.2 requires the relying party to verify that BE is
 * unchanged whenever backup state is used as policy, which it is here; and
 * §6.1.3 says BE "MUST NOT change". assertionDenialReason() is that check.
 */
final class WebAuthnDeviceBinding
{
    /** The single runtime key. Read it only through isRequired(). */
    public const CONFIG_KEY = 'webauthn.device_binding.require_device_bound';

    /** How the requirement was resolved when the config was built (reporting only). */
    public const SOURCE_CONFIG_KEY = 'webauthn.device_binding.require_device_bound_source';

    /** The variable was not set (or was Laravel's `null`/`(null)`): the shipped default, REQUIRED. */
    public const SOURCE_DEFAULT = 'default';

    /** An explicit tightening spelling: REQUIRED. */
    public const SOURCE_EXPLICIT_ON = 'explicit_on';

    /** The reviewed, out-loud relaxation: a boolean false or the exact string `0`. The only path to NOT required. */
    public const SOURCE_EXPLICIT_OFF = 'explicit_off';

    /** Present but empty (incl. whitespace, `empty`, `(empty)`): a configuration fault, read as REQUIRED. */
    public const SOURCE_BLANK = 'blank_fail_closed';

    /** Present but unparseable (incl. `off`, `no`): a configuration fault, read as REQUIRED. */
    public const SOURCE_INVALID = 'invalid_fail_closed';

    /** The runtime value is not a boolean (hand-edited cache, refactor, override): read as REQUIRED. */
    public const SOURCE_RUNTIME_INVALID = 'runtime_value_invalid_fail_closed';

    /** A genuine boolean with no recorded provenance (e.g. a runtime override that bypassed the config file). */
    public const SOURCE_UNRECORDED = 'unrecorded';

    /** The sources the config file can record. */
    public const ENVIRONMENT_SOURCES = [
        self::SOURCE_DEFAULT,
        self::SOURCE_EXPLICIT_ON,
        self::SOURCE_EXPLICIT_OFF,
        self::SOURCE_BLANK,
        self::SOURCE_INVALID,
    ];

    /** Assertion refusal codes, for the audit trail only — the client sees one opaque message. */
    public const REASON_BACKUP_ELIGIBILITY_CHANGED = 'backup_eligibility_changed';

    /**
     * The SIGNED BE bit fails the policy. Deliberately not `device_binding_policy`,
     * which is the stored-verdict refusal, so the audit trail says which check refused.
     */
    public const REASON_ASSERTED_DEVICE_BINDING_POLICY = 'asserted_device_binding_policy';

    private const TIGHTENING_SPELLINGS = ['true', '1', 'on', 'yes'];

    /**
     * Classify what env('WEBAUTHN_REQUIRE_DEVICE_BOUND') handed back.
     *
     * Called from config/webauthn.php, so it runs once at config build and its
     * answer is var_export'ed into the config cache. It must never read the
     * environment itself.
     */
    public static function classifyRequirementOverride(mixed $raw): string
    {
        if ($raw === null) {
            return self::SOURCE_DEFAULT;
        }

        if (is_bool($raw)) {
            return $raw ? self::SOURCE_EXPLICIT_ON : self::SOURCE_EXPLICIT_OFF;
        }

        if (is_int($raw)) {
            return match ($raw) {
                0 => self::SOURCE_EXPLICIT_OFF,
                1 => self::SOURCE_EXPLICIT_ON,
                default => self::SOURCE_INVALID,
            };
        }

        if (! is_string($raw)) {
            return self::SOURCE_INVALID;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === '' || $normalized === 'empty' || $normalized === '(empty)') {
            return self::SOURCE_BLANK;
        }

        if ($normalized === 'null' || $normalized === '(null)') {
            return self::SOURCE_DEFAULT;
        }

        // The ONLY string that relaxes, and only exactly: `(bool) '0'` was false.
        // A padded ' 0 ' and the strings 'false'/'(false)' were truthy (a bare
        // `false` never reaches here as a string — env() maps it to a boolean
        // first), so each of them stays REQUIRED and is reported invalid.
        if ($raw === '0') {
            return self::SOURCE_EXPLICIT_OFF;
        }

        if (in_array($normalized, ['0', 'false', '(false)'], true)) {
            return self::SOURCE_INVALID;
        }

        if (in_array($normalized, self::TIGHTENING_SPELLINGS, true) || $normalized === '(true)') {
            return self::SOURCE_EXPLICIT_ON;
        }

        return self::SOURCE_INVALID;
    }

    /**
     * The requirement the config file records: relaxed ONLY on an explicit,
     * recognised relaxation. Anything unclear is REQUIRED.
     */
    public static function resolveRequirementFromEnv(mixed $raw): bool
    {
        return self::classifyRequirementOverride($raw) !== self::SOURCE_EXPLICIT_OFF;
    }

    /**
     * The requirement and how it was arrived at, for operators. Reporting only:
     * enforcement reads isRequired(), never this.
     *
     * @return array{required: bool, source: string, valid: bool}
     */
    public static function requirementPosture(): array
    {
        $value = config(self::CONFIG_KEY, true);
        $required = self::isRequired();

        if (! is_bool($value)) {
            return ['required' => $required, 'source' => self::SOURCE_RUNTIME_INVALID, 'valid' => false];
        }

        $source = config(self::SOURCE_CONFIG_KEY);

        // No recorded provenance: a REQUIRED value is harmless whatever its
        // origin, but a relaxation nobody can show was deliberate (e.g. a cache
        // built by the pre-fix config from a blank variable) is not clean.
        if (! is_string($source) || ! in_array($source, self::ENVIRONMENT_SOURCES, true)) {
            return ['required' => $required, 'source' => self::SOURCE_UNRECORDED, 'valid' => $value !== false];
        }

        // A boolean that contradicts the source it was recorded with means the
        // cache was edited by hand or a runtime override bypassed the file. The
        // boolean is still the authority; the report must not call it clean.
        $consistent = ($source === self::SOURCE_EXPLICIT_OFF) === ($value === false);

        $valid = $consistent && ! in_array($source, [self::SOURCE_BLANK, self::SOURCE_INVALID], true);

        return ['required' => $required, 'source' => $source, 'valid' => $valid];
    }

    /**
     * Classify a credential from its backup flags.
     *
     * @param  bool|null  $backupEligible  BE, or null when the authenticator did not report it
     */
    public static function verdict(?bool $backupEligible): string
    {
        if ($backupEligible === null) {
            return DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN;
        }

        return $backupEligible
            ? DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE
            : DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND;
    }

    /**
     * Is the deployment demanding single-device credentials?
     *
     * Only a genuine boolean `false` relaxes it. A missing key, null, '', a
     * string, an integer or anything else is REQUIRED — `(bool) null` is how the
     * old reader turned "I do not know" into "not required".
     */
    public static function isRequired(): bool
    {
        return config(self::CONFIG_KEY, true) !== false;
    }

    /**
     * May a credential with this verdict be registered and used?
     *
     * When the policy is relaxed every verdict is acceptable — but the verdict
     * is still RECORDED, so a deployment that accepted syncable credentials can
     * always be asked afterwards which of its credentials are syncable.
     */
    public static function isAcceptable(string $verdict): bool
    {
        if (! self::isRequired()) {
            return true;
        }

        return $verdict === DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND;
    }

    /**
     * Why an otherwise valid assertion must still be refused, or null.
     *
     * Call ONLY after the library has verified the assertion signature: the
     * flags are trustworthy because the signature covers them, and not before.
     *
     *  1. The signed BE bit must equal the one recorded at registration
     *     (WebAuthn L3 §7.2). A credential with no recorded BE has nothing to
     *     compare against; under a requiring policy it was already refused by
     *     its `unknown` verdict.
     *  2. The signed BE bit must itself satisfy the device-binding policy, so a
     *     credential can never be judged only on what it claimed at enrolment.
     *
     * Rule 1 applies whatever the policy: an authenticator that contradicts its
     * own registration is broken or lying, and a relaxed deployment has not
     * accepted that.
     */
    public static function assertionDenialReason(?bool $recordedBackupEligible, bool $assertedBackupEligible): ?string
    {
        if ($recordedBackupEligible !== null && $recordedBackupEligible !== $assertedBackupEligible) {
            return self::REASON_BACKUP_ELIGIBILITY_CHANGED;
        }

        if (! self::isAcceptable(self::verdict($assertedBackupEligible))) {
            return self::REASON_ASSERTED_DEVICE_BINDING_POLICY;
        }

        return null;
    }
}
