<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeImport;

/**
 * LEGACY-RME-SOD-1 — THE separation-of-duties rule for the legacy archive.
 *
 * ONE RULE, ONE HOME. Before this sprint the comparison lived as an inline
 * `$uploader === $actor` inside LegacyRmeImportLifecycleService. That was
 * correct, but it made the rule a property of one class rather than of the
 * domain, and it sat entirely OUTSIDE the transaction that actually writes the
 * record. This class is the only place the comparison is expressed; the
 * lifecycle service and the publish service both ask it, and neither restates
 * it. A reviewer checking "can the rule differ between the browser, the command
 * line and a direct service call?" has exactly one method to read.
 *
 * WHAT IT ENFORCES — AND WHAT IT HONESTLY CANNOT.
 *
 *   ENFORCED (application control):  imported_by_user_id != acting_user_id
 *   NOT ENFORCED (human governance): maker human    != checker human
 *
 * The server sees authenticated ACCOUNTS. It can guarantee that two distinct
 * accounts touched a document, and it does. It cannot establish that two
 * distinct HUMANS hold those accounts — no identity mechanism in this system
 * proves that, and claiming otherwise in GO evidence would be a lie an auditor
 * could disprove in a minute. Two people sharing one login therefore CANNOT
 * satisfy this rule (desirable: shared accounts destroy accountability), and
 * one person holding two logins CAN (which is why controlled migration
 * operations still require genuinely separate human operators as an operational
 * governance control, documented in the runbook and attested by a human).
 *
 * WHICH DUTIES ARE SEPARATED, AND WHY BOTH.
 *
 * Publishing is the obvious one: the account that filed a document may not
 * certify it. Reviewing is included deliberately, because the canonical role
 * split already treats it as the CHECKER's duty — Wave-1 gave the maker
 * `create_legacy_rme_imports` and withheld BOTH `review` and `publish`, and gave
 * the checker `review`+`publish` while withholding `create`. Gating publish
 * alone would leave the "a human other than the filer actually looked at the
 * rendered pages" attestation bypassable by the one account the role split
 * cannot constrain (a Super Admin): they could file a document, review their own
 * render, and hand a second account a rubber stamp. Enforcing both closes the
 * checker boundary rather than half of it.
 *
 * WHAT IS DELIBERATELY *NOT* ENFORCED. Publishing does not additionally require
 * `reviewed_by != uploaded_by`. Rows reviewed before this rule was activated
 * would be stranded with no lawful way forward, and rewriting their attribution
 * to satisfy a rule that did not exist when they were filed is exactly the
 * history-editing this module forbids. The rule governs the transition being
 * attempted now, never a transition already recorded.
 *
 * PRE-ATTRIBUTION ROWS ARE EXEMPT. A staging row with no recorded uploader
 * predates attribution. Refusing it would strand a document nobody could ever
 * publish, and inventing an uploader to compare against would be a guess about
 * who filed it.
 */
final class SeparatePublisherGuard
{
    /**
     * The lifecycle actions this rule applies to.
     *
     * @var list<string>
     */
    public const GUARDED_ACTIONS = [
        LegacyRmeLifecycleAction::REVIEW,
        LegacyRmeLifecycleAction::PUBLISH,
    ];

    public const CONFIG_KEY = 'legacy_rme_operations.require_separate_publisher';

    public const ENV_KEY = 'LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER';

    private const MESSAGE_PUBLISH = 'Akun yang mengunggah dokumen ini tidak boleh menerbitkannya sendiri. Publikasi harus dilakukan oleh pemeriksa yang berbeda.';

    private const MESSAGE_REVIEW = 'Akun yang mengunggah dokumen ini tidak boleh meninjaunya sendiri. Peninjauan harus dilakukan oleh pemeriksa yang berbeda.';

    /**
     * Resolve the switch from its raw environment value, FAIL CLOSED.
     *
     * WHY THIS IS NOT `(bool) env(..., true)`. The failure mode that matters is
     * not someone deliberately writing `false` — it is a typo. `env()` returns
     * null for a misspelled key and '' for a key with no value, and PHP casts
     * both to false. With a plain boolean default, `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHR=true`
     * or a truncated line would silently DISABLE a production safety invariant,
     * and nothing would say so. Here anything that is not a recognised falsy
     * literal — including unset, empty, misspelled and unparseable — resolves to
     * ENABLED. Turning the rule off requires writing one of four unambiguous
     * words on purpose.
     *
     * Laravel's own env decoding has already converted the literals `true` and
     * `false` to booleans by the time this sees them; the string branch exists
     * for everything it does not decode (`0`, `off`, `no`, and any typo).
     */
    public static function resolveEnabledFromEnv(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === null) {
            return true;
        }

        if (! is_scalar($raw)) {
            return true;
        }

        return ! in_array(strtolower(trim((string) $raw)), ['false', '0', 'off', 'no'], true);
    }

    public function enabled(): bool
    {
        return (bool) config(self::CONFIG_KEY, true);
    }

    /**
     * Does this action, by this actor, on this import, breach the rule?
     *
     * THE ONLY EXPRESSION OF THE COMPARISON IN THE CODEBASE. Read-only and
     * side-effect free, so the preflight, the lifecycle gate, the in-transaction
     * re-assert and the view can all ask it without any of them mutating or
     * diverging.
     */
    public function violates(string $action, LegacyRmeImport $import, ?User $actor): bool
    {
        if (! in_array($action, self::GUARDED_ACTIONS, true)) {
            return false;
        }

        if (! $this->enabled()) {
            return false;
        }

        $uploader = $import->uploaded_by !== null ? (int) $import->uploaded_by : null;

        if ($uploader === null) {
            return false;
        }

        $actorId = $actor?->getKey();

        // No authenticated actor means nothing to compare. Every write path
        // reaching here has already required one; a null cannot be treated as a
        // match without inventing an identity.
        if ($actorId === null) {
            return false;
        }

        return $uploader === (int) $actorId;
    }

    public function message(string $action): string
    {
        return $action === LegacyRmeLifecycleAction::REVIEW
            ? self::MESSAGE_REVIEW
            : self::MESSAGE_PUBLISH;
    }

    /**
     * The stable failure code recorded in the refusal trail for this action.
     */
    public function failureCode(string $action): string
    {
        return $action === LegacyRmeLifecycleAction::REVIEW
            ? LegacyRmePdfFailure::SEPARATE_REVIEWER_REQUIRED
            : LegacyRmePdfFailure::SEPARATE_PUBLISHER_REQUIRED;
    }
}
