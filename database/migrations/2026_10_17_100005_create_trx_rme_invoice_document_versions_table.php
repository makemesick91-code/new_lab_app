<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — immutable invoice / receipt
| document versions (audit BCF-06).
|
| Invoices and receipts are rendered LIVE from current rows. Once a correction
| can change an invoice, a reprint of a pre-correction document would silently
| show corrected numbers under the same document number. This table freezes the
| document at the only moment it changes — correction APPLICATION:
|
|   version 0  ORIGINAL  — the document exactly as it stood before the first
|                          correction (captured from the locked live rows, whose
|                          fingerprint is re-verified against the one the
|                          correction was approved on). Before any correction
|                          path existed an invoice had no mutation path at all
|                          (rule 188 §2), so the live rows at that instant ARE
|                          the original; `capture_basis` says so explicitly.
|   version N  REVISION  — the document after the Nth applied correction,
|                          linked to the correction that produced it.
|
| The snapshot carries payment references, so the same row reproduces the
| RECEIPT that was printable at that moment (receipts exist only for PAID
| invoices). Versions are written once and never updated or deleted (model
| guard); "superseded" is derived from a higher version existing, never stored.
|
| No patient name, Nomor RM or KTP/NIK is stored in the snapshot — patient
| identity is not the financial content that changes, and is joined live.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_invoice_document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('version_kind', 20);
            $table->string('document_reference', 60);
            $table->foreignId('source_correction_id')
                ->nullable()
                ->constrained('trx_rme_invoice_corrections')
                ->restrictOnDelete();
            $table->string('capture_basis', 60);
            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->jsonb('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['rme_invoice_id', 'version_number'], 'trx_rme_inv_doc_versions_number_uq');
            $table->index(['branch_id', 'issued_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_invoice_document_versions ADD CONSTRAINT trx_rme_inv_doc_versions_kind_chk CHECK (version_kind IN ('ORIGINAL', 'REVISION'))");
            DB::statement("ALTER TABLE trx_rme_invoice_document_versions ADD CONSTRAINT trx_rme_inv_doc_versions_shape_chk CHECK ((version_number = 0 AND version_kind = 'ORIGINAL' AND source_correction_id IS NULL) OR (version_number > 0 AND version_kind = 'REVISION' AND source_correction_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_invoice_document_versions');
    }
};
