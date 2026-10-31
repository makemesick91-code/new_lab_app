<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; owner decision RF-1a) —
 * the source-controlled pilot identity allowlist. It ships EMPTY, so it admits
 * nobody: the Sprint 2 maker/checker pair is NOT inherited. It is filled only at
 * activation, with accounts whose human separation was verified (RF-1a).
 *
 * The allowlist only NARROWS the role + branch authority of RF-1; it never grants.
 * A maker id that is also a reviewer id voids the whole list (fail-closed).
 */
final class RmeRefundRequestPilotIdentity
{
    public const CONFIG_KEY = 'rme_refund.pilot.identities';

    public const ROLE_MAKER = 'maker';

    public const ROLE_REVIEWER = 'reviewer';

    /** @return array{maker_user_ids: list<int>, reviewer_user_ids: list<int>}|null */
    public function allowlist(): ?array
    {
        $raw = config(self::CONFIG_KEY);
        if (! is_array($raw)) {
            return null;
        }

        $makers = $this->ids($raw['maker_user_ids'] ?? null);
        $reviewers = $this->ids($raw['reviewer_user_ids'] ?? null);
        if ($makers === null || $reviewers === null || array_intersect($makers, $reviewers) !== []) {
            return null;
        }

        return ['maker_user_ids' => $makers, 'reviewer_user_ids' => $reviewers];
    }

    public function designates(string $role, int $userId): bool
    {
        $list = $this->allowlist();
        if ($list === null || $userId <= 0) {
            return false;
        }

        return match ($role) {
            self::ROLE_MAKER => in_array($userId, $list['maker_user_ids'], true),
            self::ROLE_REVIEWER => in_array($userId, $list['reviewer_user_ids'], true),
            default => false,
        };
    }

    /** @return list<int>|null */
    private function ids(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $ids = [];
        foreach ($raw as $value) {
            if (! is_int($value) || $value <= 0) {
                return null; // a malformed entry voids the list rather than being skipped
            }
            $ids[$value] = $value;
        }

        return array_values($ids);
    }
}
