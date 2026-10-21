<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Models\User;

/**
 * HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 (rule 197,
 * owner decision D1e, 2026-10-21) — WHO may act in the correction pilot.
 *
 * {@see RmeInvoiceCorrectionPilotScope} decides WHERE an ON flag reaches (SPN4).
 * This class decides WHO: two named ACCOUNTS, never a role. The permission grant
 * reaches every Front Office account and every Super Admin; the owner narrowed
 * the pilot to the maker (user 29) and the checker (user 1).
 *
 *   SUBMIT                 -> maker_user_ids
 *   APPROVE (= apply, D1a) -> checker_user_ids
 *   REJECT                 -> checker_user_ids
 *   APPLY (recovery path)  -> checker_user_ids
 *
 * CANCEL is deliberately absent: a maker withdrawing their OWN correction is a
 * de-escalation, already confined to that one account by the service
 * (NOT_THE_MAKER), and it is the release path that keeps a narrowed list from
 * stranding an invoice behind the D10 payment block.
 *
 * This is an ADDITIONAL restriction. It never replaces the flag, the permission,
 * the working-branch scope, the branch allowlist or maker != checker — the
 * service asserts all of them, and only then asks this class.
 *
 * FAIL CLOSED, in every direction:
 *   - the list is read from source-controlled config only (no environment);
 *   - both lists must be PRESENT lists of POSITIVE INTEGERS — a string id, a
 *     zero, a missing list or a non-array voids the WHOLE allowlist (one
 *     unreadable entry is never skipped: skipping it would leave the rest
 *     looking authoritative while the file says something else);
 *   - an EMPTY list is readable and admits nobody to that role only. Emptying
 *     the maker list is how new submissions are stopped while the checker
 *     still resolves (rejects) what is already filed — a release path must not
 *     disappear because the OTHER role was narrowed;
 *   - SUBMIT additionally needs at least one designated checker that is a
 *     usable (persisted, active) account: a correction nobody may decide would
 *     hold its invoice behind the D10 payment block;
 *   - the two lists must not overlap — one account never holds both halves;
 *   - the actor must be a PERSISTED, non-deleted, ACTIVE account, re-read from
 *     the database (an in-memory object carrying a designated id is not that
 *     account, and a deactivation committed elsewhere is seen).
 */
final class RmeInvoiceCorrectionPilotIdentity
{
    public const CONFIG_KEY = 'rme_invoice_correction.pilot.identities';

    public const ACTION_SUBMIT = 'submit';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_APPLY = 'apply';

    private const MAKER_ACTIONS = [self::ACTION_SUBMIT];

    private const CHECKER_ACTIONS = [self::ACTION_APPROVE, self::ACTION_REJECT, self::ACTION_APPLY];

    /**
     * The validated allowlist, or null when it is missing or malformed (which
     * admits nobody).
     *
     * @return array{maker_user_ids: list<int>, checker_user_ids: list<int>}|null
     */
    public function allowlist(): ?array
    {
        $raw = config(self::CONFIG_KEY);

        if (! is_array($raw)) {
            return null;
        }

        $makers = $this->ids($raw['maker_user_ids'] ?? null);
        $checkers = $this->ids($raw['checker_user_ids'] ?? null);

        if ($makers === null || $checkers === null) {
            return null;
        }

        if (array_intersect($makers, $checkers) !== []) {
            return null;
        }

        return ['maker_user_ids' => $makers, 'checker_user_ids' => $checkers];
    }

    public function isValid(): bool
    {
        return $this->allowlist() !== null;
    }

    /**
     * True when the allowlist designates this account id for the action. Says
     * nothing about whether the account is usable — see allows().
     */
    public function designates(string $action, int $userId): bool
    {
        $list = $this->allowlist();

        if ($list === null || $userId <= 0) {
            return false;
        }

        if (in_array($action, self::MAKER_ACTIONS, true)) {
            return in_array($userId, $list['maker_user_ids'], true)
                && $this->hasUsableChecker($list['checker_user_ids']);
        }

        if (in_array($action, self::CHECKER_ACTIONS, true)) {
            return in_array($userId, $list['checker_user_ids'], true);
        }

        return false; // an action this class does not know is never allowed
    }

    /**
     * The designation and a usable account, without throwing — for UI
     * courtesy and the readiness probe. The service uses assertAllowed().
     */
    public function allows(string $action, ?User $actor): bool
    {
        return $actor !== null && $this->usable($actor) && $this->designates($action, (int) $actor->getKey());
    }

    /**
     * @throws RmeInvoiceCorrectionDenied
     */
    public function assertAllowed(string $action, ?User $actor, ?int $correctionId = null, ?int $invoiceId = null): void
    {
        if ($actor === null || ! $this->usable($actor)) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::PILOT_IDENTITY_ACTOR_UNUSABLE,
                'Akun ini tidak aktif atau tidak dikenali, sehingga tidak dapat menjalankan koreksi tagihan.',
                $correctionId,
                $invoiceId,
            );
        }

        if (! $this->designates($action, (int) $actor->getKey())) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::PILOT_IDENTITY_NOT_AUTHORIZED,
                'Akun Anda tidak ditunjuk untuk tindakan ini dalam pilot koreksi tagihan.',
                $correctionId,
                $invoiceId,
            );
        }
    }

    /**
     * Rule 197 — the designated ids that are NOT a persisted, non-deleted,
     * active account (for the readiness probe; e.g. a deactivated checker, or a
     * deployment where the production ids do not exist).
     *
     * @return list<int>
     */
    public function unusableDesignatedIds(): array
    {
        $list = $this->allowlist();
        if ($list === null) {
            return [];
        }

        $designated = array_values(array_unique(array_merge($list['maker_user_ids'], $list['checker_user_ids'])));
        if ($designated === []) {
            return [];
        }

        $usable = User::query()->whereKey($designated)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unusable = array_values(array_diff($designated, $usable));
        sort($unusable);

        return $unusable;
    }

    /**
     * A correction nobody may decide would hold its invoice behind the D10
     * payment block, so a submission needs a checker who can actually act.
     *
     * @param  list<int>  $checkerIds
     */
    private function hasUsableChecker(array $checkerIds): bool
    {
        return $checkerIds !== []
            && User::query()->whereKey($checkerIds)->where('is_active', true)->exists();
    }

    /**
     * A persisted, non-deleted, active account — re-read from the database, so
     * neither an unsaved object carrying an id nor a stale in-memory copy of a
     * since-deactivated account passes.
     */
    private function usable(User $actor): bool
    {
        if (! $actor->exists || $actor->getKey() === null) {
            return false;
        }

        return User::query()
            ->whereKey($actor->getKey())
            ->where('is_active', true)
            ->exists(); // the SoftDeletes scope excludes a deleted account
    }

    /**
     * @return list<int>|null null when the value is not a list of positive integers (an empty list is valid)
     */
    private function ids(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $id) {
            if (! is_int($id) || $id <= 0) {
                return null;
            }
        }

        $ids = array_values(array_unique($value));
        sort($ids);

        return $ids;
    }
}
