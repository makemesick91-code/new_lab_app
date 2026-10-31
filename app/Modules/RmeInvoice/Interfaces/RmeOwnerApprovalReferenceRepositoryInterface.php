<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

/**
 * HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — persistence of consumed Owner
 * approval references. Keys are always the CASE-FOLDED reference; folding and
 * every decision belong to RmeOwnerApprovalReferenceRegistry.
 */
interface RmeOwnerApprovalReferenceRepositoryInterface
{
    /**
     * Claims the key for the scope unless any scope already owns it:
     * INSERT … ON CONFLICT (reference_folded) DO NOTHING. Never raises a unique
     * violation, so a PostgreSQL transaction is never aborted by a refusal.
     */
    public function insertIfAbsent(string $folded, string $scopeType, int $scopeId, int $actorId): void;

    /**
     * The scope that owns the key, read in the caller's transaction.
     *
     * @return array{scope_type: string, scope_id: int}|null
     */
    public function scopeOf(string $folded): ?array;

    public function exists(): bool;

    public function count(): int;

    /**
     * POST_PAYMENT corrections whose recorded reference is not claimed by the
     * registry in the scope of their own invoice (read-only integrity probe).
     */
    public function unregisteredPostPaymentCorrectionCount(): int;
}
