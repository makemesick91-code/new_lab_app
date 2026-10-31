<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeOwnerApprovalReferenceRepositoryInterface;
use App\Modules\RmeInvoice\Services\RmeOwnerApprovalReferenceRegistry;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RmeOwnerApprovalReferenceRepository implements RmeOwnerApprovalReferenceRepositoryInterface
{
    private const TABLE = 'trx_rme_owner_approval_references';

    public function insertIfAbsent(string $folded, string $scopeType, int $scopeId, int $actorId): void
    {
        $now = now();

        // The same statement on PostgreSQL and on SQLite (3.24+ UPSERT syntax).
        // The conflict target is the folded key only: a CHECK or foreign-key
        // violation still raises.
        DB::insert(
            'INSERT INTO '.self::TABLE.' (reference_folded, scope_type, scope_id, first_consumed_by, first_consumed_at, created_at) '
            .'VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (reference_folded) DO NOTHING',
            [$folded, $scopeType, $scopeId, $actorId, $now, $now],
        );
    }

    public function scopeOf(string $folded): ?array
    {
        $row = DB::table(self::TABLE)->where('reference_folded', $folded)->first(['scope_type', 'scope_id']);

        return $row === null ? null : ['scope_type' => (string) $row->scope_type, 'scope_id' => (int) $row->scope_id];
    }

    public function exists(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }

    public function unregisteredPostPaymentCorrectionCount(): int
    {
        return DB::table('trx_rme_invoice_corrections as c')
            ->where('c.correction_type', RmeInvoiceCorrectionType::POST_PAYMENT)
            ->whereNotNull('c.owner_approval_reference')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from(self::TABLE.' as r')
                    ->whereRaw('r.reference_folded = lower(trim(c.owner_approval_reference))')
                    ->where('r.scope_type', RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE)
                    ->whereColumn('r.scope_id', 'c.rme_invoice_id');
            })
            ->count();
    }
}
