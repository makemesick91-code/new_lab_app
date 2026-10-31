<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; owner decision RF-5 = B) —
| the registry of consumed PATIENT request references. A patient reference may be
| repeated by a re-filed request for the SAME obligation and never used for another
| one; no index on the request table can say that, so it is consumed here with
| INSERT … ON CONFLICT DO NOTHING plus an obligation comparison (migration plan §2b).
|
| Append-only: no updated_at, no soft delete; the model refuses update and delete;
| down() refuses while a row exists.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_refund_patient_references';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('reference_folded', 120)->unique('trx_rme_refund_pat_ref_folded_uq');
            $table->foreignId('rme_overpayment_obligation_id')->constrained('trx_rme_overpayment_obligations', 'id', 'trx_rme_refund_pat_ref_obligation_fk')->restrictOnDelete();
            $table->foreignId('first_consumed_by')->constrained('users', 'id', 'trx_rme_refund_pat_ref_consumed_by_fk')->restrictOnDelete();
            $table->timestampTz('first_consumed_at');
            $table->timestamp('created_at')->nullable();

            $table->index('rme_overpayment_obligation_id', 'trx_rme_refund_pat_ref_obligation_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_refund_patient_references ADD CONSTRAINT trx_rme_refund_pat_ref_folded_chk CHECK (reference_folded ~ '^[a-z0-9._:/-]{6,120}$')");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_refund_patient_references: it holds consumed patient references.');
        }

        Schema::dropIfExists(self::TABLE);
    }
};
