<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — invoice correction requests.
|
| A correction is a FINANCIAL RECORD, never a mutation of history:
|   - the original document is captured at submission (`original_snapshot`,
|     schema-versioned jsonb) and fingerprinted (`invoice_fingerprint`);
|   - the proposal is captured beside it (`proposed_snapshot`, plus one row per
|     line in trx_rme_invoice_correction_lines);
|   - approval re-verifies the fingerprint under the invoice row lock, and
|     application re-verifies it again before touching a single item.
|
| NO SOFT DELETE: a correction that existed is part of the invoice's history.
| Cancellation, rejection and staleness are statuses, never deletions.
|
| ONE ACTIVE CORRECTION PER INVOICE is a database invariant (partial unique
| index below), not only a service check — two concurrent submissions can never
| both become active.
|
| `branch_id` is ALWAYS the corrected invoice's branch (D13 proposal), copied
| from the locked invoice row by the service and never read from a request.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('correction_number', 40)->nullable()->unique();

            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->restrictOnDelete();

            $table->string('correction_type', 20);
            $table->string('status', 20);
            $table->text('reason');

            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->unsignedInteger('invoice_version_at_request');
            $table->char('invoice_fingerprint', 64);
            $table->jsonb('original_snapshot');
            $table->jsonb('proposed_snapshot');

            $table->decimal('original_grand_total', 15, 2);
            $table->decimal('proposed_grand_total', 15, 2);
            $table->decimal('difference_amount', 15, 2);
            $table->decimal('paid_total_at_request', 15, 2);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');

            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('applied_invoice_version')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamp('stale_at')->nullable();
            $table->string('stale_reason_code', 60)->nullable();

            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->timestamps();

            $table->index(['branch_id', 'requested_at']);
            $table->index(['status', 'requested_at']);
            $table->index('rme_invoice_id');
            $table->index('patient_id');
            $table->index('requested_by');
        });

        // At most one ACTIVE (SUBMITTED / APPROVED) correction per invoice.
        DB::statement(
            'CREATE UNIQUE INDEX trx_rme_inv_corr_one_active_uq '
            ."ON trx_rme_invoice_corrections (rme_invoice_id) WHERE status IN ('SUBMITTED', 'APPROVED')"
        );

        // Value-domain guards. PostgreSQL only: SQLite cannot add a constraint
        // to an existing table, and the service enforces the same domains on
        // every engine. Production runs PostgreSQL, where these hold even
        // against a raw-SQL writer.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_status_chk CHECK (status IN ('SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED', 'STALE', 'APPLIED'))");
            DB::statement("ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_type_chk CHECK (correction_type IN ('PRE_PAYMENT', 'POST_PAYMENT'))");
            DB::statement('ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_totals_chk CHECK (original_grand_total >= 0 AND proposed_grand_total >= 0 AND paid_total_at_request >= 0)');
            DB::statement('ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_difference_chk CHECK (difference_amount = proposed_grand_total - original_grand_total)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_invoice_corrections');
    }
};
