<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (owner decision OPS-OQ-1,
| rule 203) — a billing dispute: an invoice whose amount is known or suspected
| to be wrong, reported by a cashier and escalated to the branch authority.
|
| A DISPUTE IS NOT A CORRECTION. It never changes the invoice: no total, item,
| status, payment, version or snapshot is written by any dispute transition. It
| only HOLDS collection — every RME payment path refuses an invoice with an
| ACTIVE dispute under the invoice row lock — until a legitimate, authorized,
| auditable resolution releases it.
|
| ONE ACTIVE DISPUTE PER INVOICE is a database invariant (partial unique index
| below), not only a service check: two concurrent reports can never both
| become active. Resolved disputes stay as history (no soft delete — a dispute
| that existed is part of the invoice's financial history).
|
| `branch_id` is ALWAYS the disputed invoice's branch, copied from the locked
| invoice row by the service and never read from a request. A dispute never
| reassigns the invoice and never moves a receivable between branches (OD-1a).
|
| The `*_at_report` columns are a small immutable record of what was disputed
| (status, totals, correction_version) — ids and numbers only. They are what a
| resolution is checked against: "the invoice was corrected AFTER the report"
| is decided from `invoice_correction_version_at_report`, never from a request.
|
| Free text (description, notes, resolution reason) may carry operator prose;
| audit payloads record only its LENGTH. Nothing here stores a patient name,
| NIK, medical record number or clinical content.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_billing_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('dispute_number', 40)->nullable()->unique();

            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();

            $table->string('status', 20);
            $table->string('reason_category', 40);
            $table->text('description');

            $table->string('invoice_status_at_report', 20);
            $table->decimal('invoice_grand_total_at_report', 15, 2);
            $table->decimal('invoice_paid_total_at_report', 15, 2);
            $table->unsignedInteger('invoice_correction_version_at_report');

            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reported_at');

            $table->foreignId('review_started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('review_started_at')->nullable();

            $table->foreignId('escalated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('escalated_at')->nullable();

            $table->string('resolution_outcome', 40)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->string('resolution_reference', 120)->nullable();
            $table->foreignId('resolution_correction_id')->nullable()
                ->constrained('trx_rme_invoice_corrections')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['status', 'reported_at']);
            $table->index('rme_invoice_id');
            $table->index('reported_by');
        });

        // At most one ACTIVE (OPEN / UNDER_REVIEW / ESCALATED) dispute per invoice.
        DB::statement(
            'CREATE UNIQUE INDEX trx_rme_billing_dispute_one_active_uq '
            ."ON trx_rme_billing_disputes (rme_invoice_id) WHERE status IN ('OPEN', 'UNDER_REVIEW', 'ESCALATED')"
        );

        // Value-domain guards. PostgreSQL only: SQLite cannot add a constraint to
        // an existing table, and the service enforces the same domains on every
        // engine. Production runs PostgreSQL, where these hold even against a
        // raw-SQL writer.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_billing_disputes ADD CONSTRAINT trx_rme_billing_dispute_status_chk CHECK (status IN ('OPEN', 'UNDER_REVIEW', 'ESCALATED', 'RESOLVED'))");
            DB::statement("ALTER TABLE trx_rme_billing_disputes ADD CONSTRAINT trx_rme_billing_dispute_reason_chk CHECK (reason_category IN ('AMOUNT_INCORRECT', 'ITEM_INCORRECT', 'DUPLICATE_CHARGE', 'DISCOUNT_INCORRECT', 'OTHER'))");
            DB::statement("ALTER TABLE trx_rme_billing_disputes ADD CONSTRAINT trx_rme_billing_dispute_outcome_chk CHECK (resolution_outcome IS NULL OR resolution_outcome IN ('CORRECTION_APPLIED', 'ORIGINAL_CONFIRMED_CORRECT'))");
            // A RESOLVED dispute always carries who, when and how; an active one
            // never carries any of it.
            DB::statement("ALTER TABLE trx_rme_billing_disputes ADD CONSTRAINT trx_rme_billing_dispute_resolution_chk CHECK ((status = 'RESOLVED' AND resolution_outcome IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL) OR (status <> 'RESOLVED' AND resolution_outcome IS NULL AND resolved_by IS NULL AND resolved_at IS NULL))");
            DB::statement('ALTER TABLE trx_rme_billing_disputes ADD CONSTRAINT trx_rme_billing_dispute_totals_chk CHECK (invoice_paid_total_at_report >= 0)');
        }

        Schema::create('trx_rme_billing_dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_billing_dispute_id')
                ->constrained('trx_rme_billing_disputes')
                ->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rme_billing_dispute_id', 'occurred_at'], 'trx_rme_billing_dispute_events_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_billing_dispute_events');
        Schema::dropIfExists('trx_rme_billing_disputes');
    }
};
