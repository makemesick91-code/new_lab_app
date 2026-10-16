<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — append-only transition history.
|
| One row per status transition (including the initial submission, from NULL).
| There is no `updated_at`: an event is never edited. The detail screens and the
| oversight timeline read this table, so the history never depends on columns
| of the correction row that a later transition overwrites.
|
| `metadata` carries ids, amounts and stable codes only — never a patient name,
| KTP/NIK, item description or the free-text reason (its LENGTH is recorded).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_invoice_correction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_invoice_correction_id')
                ->constrained('trx_rme_invoice_corrections')
                ->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rme_invoice_correction_id', 'occurred_at'], 'trx_rme_inv_corr_events_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_invoice_correction_events');
    }
};
