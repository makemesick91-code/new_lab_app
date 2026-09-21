<?php

namespace App\Support\AccessControl;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — who is armed, and for which branch.
 *
 * THE COHORT IS THE SCOPE. NOT THE ROLE.
 *
 * Production carries EIGHT `Front Office` accounts. The owner approved the lock
 * for exactly four of them, so scope cannot be `hasRole('Front Office')`: that
 * would device-lock four live front-desk accounts nobody asked about, including
 * user 7, who selected a branch and worked on the day this was written.
 *
 * So membership is by user id, and the id must be paired with the branch that
 * account is expected to work at. Nothing here reads a display name: two
 * accounts can share a name, a name can be edited by anyone with user-admin
 * rights, and neither is true of a primary key.
 *
 * PARSING IS STRICT AND FAILS CLOSED, WHICH HERE MEANS "DENY", NOT "SKIP".
 *
 * There is a real difference between the two, and getting it backwards is how a
 * lock becomes decorative:
 *
 *   - An id that is ABSENT from the cohort is OUT OF SCOPE. Nothing is enforced
 *     and its login is untouched. That is the whole point of a four-account
 *     lock and is not a failure mode.
 *   - An id that is PRESENT but whose entry cannot be trusted — duplicated,
 *     naming a branch outside the committed allowlist, or sitting in an
 *     oversized cohort — is IN SCOPE and UNDECIDABLE, so it DENIES. A
 *     misconfigured lock must not resolve to "allow".
 *
 * A token that cannot be attributed to any user id at all (`abc:SPN4`, or a
 * bare `SPN4`) is recorded as a configuration error and puts NOBODY in scope.
 * It cannot deny an account it cannot name, and it must never widen scope to
 * one it was not pointed at.
 */
final class FrontOfficeBranchDeviceCohort
{
    /** @var array<int, string>|null */
    private ?array $resolved = null;

    /** @var array<int, true> */
    private array $ambiguous = [];

    /** @var list<string> */
    private array $configErrors = [];

    private bool $oversized = false;

    /**
     * Is this account armed — whether or not its entry is usable?
     *
     * Deliberately true for an ambiguous entry, so that an in-scope account with
     * a broken mapping reaches the decision service and is DENIED there rather
     * than quietly falling out of scope and being admitted.
     */
    public function covers(int $userId): bool
    {
        $this->parse();

        return array_key_exists($userId, $this->resolved) || isset($this->ambiguous[$userId]);
    }

    /**
     * The branch code this account is required to be on, or NULL when the entry
     * is present but undecidable. A NULL for a covered account is a DENIAL, and
     * the caller must treat it as one.
     */
    public function requiredBranchCodeFor(int $userId): ?string
    {
        $this->parse();

        if (isset($this->ambiguous[$userId])) {
            return null;
        }

        return $this->resolved[$userId] ?? null;
    }

    /** @return array<int, string> */
    public function armed(): array
    {
        $this->parse();

        return $this->resolved;
    }

    /** @return list<int> */
    public function ambiguousUserIds(): array
    {
        $this->parse();

        return array_map('intval', array_keys($this->ambiguous));
    }

    /**
     * Configuration problems worth surfacing to an operator. Never a reason to
     * admit anybody; reported so a silent typo does not look like a working lock.
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        $this->parse();

        return $this->configErrors;
    }

    public function isEmpty(): bool
    {
        $this->parse();

        return $this->resolved === [] && $this->ambiguous === [];
    }

    private function parse(): void
    {
        if ($this->resolved !== null) {
            return;
        }

        $this->resolved = [];

        $raw = (string) config('front_office_device_lock.scope.cohort', '');
        $allowed = (array) config('front_office_device_lock.policy.allowed_branch_codes', []);
        $max = (int) config('front_office_device_lock.policy.max_cohort_size', 0);

        $seen = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            // Exact shape only: `<digits>:<CODE>`. No whitespace tolerance inside
            // the pair, no alternative separator, no case folding of the id.
            if (! preg_match('/^(\d{1,19}):([A-Za-z0-9_-]{1,32})$/', $token, $m)) {
                $this->configErrors[] = 'malformed cohort entry ignored: it names no usable user id';

                continue;
            }

            $userId = (int) $m[1];
            $code = strtoupper($m[2]);

            if ($userId <= 0) {
                $this->configErrors[] = 'malformed cohort entry ignored: it names no usable user id';

                continue;
            }

            // A repeated id is ambiguous even when both entries agree: an
            // operator who wrote the id twice did not necessarily mean the same
            // branch twice, and guessing which they meant is not this class's
            // job.
            if (isset($seen[$userId])) {
                $this->ambiguous[$userId] = true;
                unset($this->resolved[$userId]);
                $this->configErrors[] = 'duplicate cohort entry for user '.$userId.' — denying, mapping is ambiguous';

                continue;
            }

            $seen[$userId] = true;

            if (! in_array($code, $allowed, true)) {
                $this->ambiguous[$userId] = true;
                $this->configErrors[] = 'cohort entry for user '.$userId.' names branch code outside the approved policy';

                continue;
            }

            $this->resolved[$userId] = $code;
        }

        // Scope creep guard. An environment that arms a fifth account has left
        // the approved scope, and the safe reading of "too many" is to trust
        // none of them rather than to pick four.
        if ($max > 0 && (count($this->resolved) + count($this->ambiguous)) > $max) {
            $this->oversized = true;

            foreach (array_keys($this->resolved) as $userId) {
                $this->ambiguous[(int) $userId] = true;
            }

            $this->resolved = [];
            $this->configErrors[] = 'cohort exceeds the approved maximum of '.$max.' accounts — denying every armed account';
        }
    }

    public function isOversized(): bool
    {
        $this->parse();

        return $this->oversized;
    }
}
