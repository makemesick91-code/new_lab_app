<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — the registry of consumed Owner approval
| references (decision PP-2; refund decision package, migration plan §2a).
|
| One row per reference, keyed by its case-folded form (UNIQUE), bound to the ONE
| decision scope it authorises: the invoice for a post-payment correction (so a
| resubmission on that invoice may repeat it, PP-2), or a refund request. A
| consumer claims a reference with INSERT … ON CONFLICT DO NOTHING and then
| compares the owning scope — never by catching a unique violation.
|
| Append-only: no updated_at, no soft delete; the model refuses update and
| delete, and down() refuses while a claim exists.
|
| Existing data is backfilled from the post-payment corrections already stored.
| A reference recorded on more than one invoice is NOT silently resolved: the
| migration refuses, and a human decides.
|
| PostgreSQL CHECKs are driver-guarded like every financial migration since
| 2026-10-17; SQLite carries the UNIQUE only.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_owner_approval_references';

    private const SCOPE_POST_PAYMENT_CORRECTION_INVOICE = 'POST_PAYMENT_CORRECTION_INVOICE';

    private const FOLDED = '/^[a-z0-9._:\/-]{6,120}$/D';

    public function up(): void
    {
        $claims = $this->existingClaims();

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('reference_folded', 120)->unique('trx_rme_owner_ref_folded_uq');
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('first_consumed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('first_consumed_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['scope_type', 'scope_id'], 'trx_rme_owner_ref_scope_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_owner_approval_references ADD CONSTRAINT trx_rme_owner_ref_folded_chk CHECK (reference_folded ~ '^[a-z0-9._:/-]{6,120}$')");
            DB::statement("ALTER TABLE trx_rme_owner_approval_references ADD CONSTRAINT trx_rme_owner_ref_scope_chk CHECK (scope_type IN ('POST_PAYMENT_CORRECTION_INVOICE', 'REFUND_REQUEST'))");
            DB::statement('ALTER TABLE trx_rme_owner_approval_references ADD CONSTRAINT trx_rme_owner_ref_scope_id_chk CHECK (scope_id > 0)');
        }

        if ($claims !== []) {
            DB::table(self::TABLE)->insert($claims);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop the Owner approval reference registry: it holds consumed references.');
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * One claim per folded reference of the post-payment corrections already
     * stored, scoped to their invoice, attributed to the earliest submission.
     *
     * @return list<array<string, mixed>>
     */
    private function existingClaims(): array
    {
        if (! Schema::hasTable('trx_rme_invoice_corrections')) {
            return [];
        }

        $rows = DB::table('trx_rme_invoice_corrections')
            ->where('correction_type', 'POST_PAYMENT')
            ->whereNotNull('owner_approval_reference')
            ->orderBy('id')
            ->get(['id', 'rme_invoice_id', 'owner_approval_reference', 'requested_by', 'requested_at']);

        $claims = [];
        foreach ($rows as $row) {
            $folded = strtolower(trim((string) $row->owner_approval_reference));
            if (preg_match(self::FOLDED, $folded) !== 1) {
                throw new RuntimeException('Refusing to create the Owner approval reference registry: correction '.$row->id.' carries a reference outside the reference alphabet.');
            }

            if (isset($claims[$folded])) {
                if ($claims[$folded]['scope_id'] !== (int) $row->rme_invoice_id) {
                    throw new RuntimeException('Refusing to create the Owner approval reference registry: a reference recorded on more than one invoice (corrections of invoices '.$claims[$folded]['scope_id'].' and '.$row->rme_invoice_id.'). Resolve it before migrating.');
                }

                continue;
            }

            $claims[$folded] = [
                'reference_folded' => $folded,
                'scope_type' => self::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
                'scope_id' => (int) $row->rme_invoice_id,
                'first_consumed_by' => (int) $row->requested_by,
                'first_consumed_at' => $row->requested_at,
                'created_at' => now(),
            ];
        }

        return array_values($claims);
    }
};
