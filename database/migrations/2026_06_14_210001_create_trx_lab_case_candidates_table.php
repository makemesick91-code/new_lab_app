<?php

// Sprint 21 Phase 21.2 — Staging table for RME → Lab Order integration.
// One candidate per eligible RME invoice item (requires_lab = true).
// UNIQUE(rme_invoice_item_id) enforces idempotency; firstOrCreate uses this key.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_case_candidates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('clinic_visit_id')
                ->constrained('trx_clinic_visits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('rme_invoice_id')
                ->constrained('trx_rme_invoices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('rme_invoice_item_id')
                ->unique()
                ->constrained('trx_rme_invoice_items')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('treatment_id')
                ->nullable()
                ->constrained('mst_treatments')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('medical_record_id')
                ->nullable()
                ->constrained('trx_medical_records')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('source_description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('estimated_price', 15, 2)->nullable();

            // pending_review → converted_to_lab_order | rejected | cancelled
            $table->string('status')->default('pending_review');

            // Nullable unsignedBigInteger — not a FK to avoid hard coupling with
            // trx_lab_orders; the Lab module owns that table and this reference
            // is informational. FK can be added in a later migration if needed.
            $table->unsignedBigInteger('converted_lab_order_id')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for common query patterns
            $table->index(['branch_id', 'status']);
            $table->index('clinic_visit_id');
            $table->index('rme_invoice_id');
            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index('treatment_id');
            $table->index('converted_lab_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_case_candidates');
    }
};
