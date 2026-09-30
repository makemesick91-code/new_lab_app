<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 — staging for mass legacy intake.
 *
 * ADDITIVE ONLY. Two new tables, no column dropped, no existing table altered,
 * no backfill. `migrate` only — never migrate:fresh / db:wipe on the VPS.
 *
 * WHY STAGING AND NOT A CLINICAL TABLE
 * ------------------------------------
 * A mass batch is an operator work-queue, not a clinical record. Nothing here
 * is the archive: the archive is `stg_rme_legacy_imports` /
 * `stg_odontogram_legacy_imports` and their published `trx_*` records, created
 * exclusively by the canonical single-item services. These two tables only
 * remember which rows an operator submitted, what the server decided about
 * each, and which canonical import (if any) each row eventually produced.
 *
 * That separation is what keeps the single-active-document rule honest: a mass
 * item does not occupy a patient's slot. Only a real canonical import row does.
 * An item that never reached createFromUpload() has no clinical existence at
 * all, which is precisely why a BLOCKED item is cheap and safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stg_legacy_mass_upload_batches')) {
            Schema::create('stg_legacy_mass_upload_batches', function (Blueprint $table): void {
                $table->id();

                // Public handle used in URLs and audit payloads so the numeric
                // id is never the thing an operator pastes around.
                $table->uuid('uuid')->unique();

                // LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. Stored as a
                // string, matching every other legacy staging table in this
                // codebase (no native enum anywhere in app/).
                $table->string('import_type', 40)->index();

                $table->string('status', 40)->index();

                // The operator. RESTRICT, never cascade: deleting a user must
                // not silently erase who submitted a clinical migration batch.
                $table->foreignId('created_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                // Whoever pressed Confirm. May differ from created_by; both are
                // recorded because "who reviewed the preflight" is the question
                // an audit actually asks.
                $table->foreignId('confirmed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // Provenance only. This is the operator's BranchContext at
                // upload time. It is NOT the authorization boundary and it is
                // NOT where an item's origin branch comes from — each item's
                // branch is resolved per patient by the canonical service.
                $table->foreignId('origin_branch_id')
                    ->nullable()
                    ->constrained('mst_branches')
                    ->nullOnDelete();

                $table->string('package_original_name')->nullable();
                $table->string('package_disk', 64);
                $table->string('package_path');

                // NOT unique, deliberately. The same archive may legitimately
                // be re-uploaded after a cancel, or after the operator fixes a
                // manifest, and a unique index here would turn a normal retry
                // into a hard error. Duplicate detection at the CLINICAL level
                // is the single-item service's job and keys off the document,
                // not the archive.
                $table->string('package_sha256', 64)->index();
                $table->unsignedBigInteger('package_bytes');

                $table->string('manifest_sha256', 64)->nullable();

                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('eligible_items')->default(0);
                $table->unsignedInteger('warning_items')->default(0);
                $table->unsignedInteger('blocked_items')->default(0);
                $table->unsignedInteger('error_items')->default(0);
                $table->unsignedInteger('dispatched_items')->default(0);
                $table->unsignedInteger('failed_items')->default(0);

                // Package-integrity refusal (§17). A batch that failed here
                // never dispatched a single item.
                $table->string('failure_code', 64)->nullable();
                $table->text('failure_message')->nullable();

                $table->timestamp('preflight_completed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('dispatch_started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                // Set when the extraction workspace has been removed, so a
                // sweeper can tell "already cleaned" from "orphaned".
                $table->timestamp('workspace_cleaned_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['import_type', 'status'], 'stg_lmu_batches_type_status_index');
                $table->index(['created_by', 'created_at'], 'stg_lmu_batches_creator_index');
            });
        }

        if (! Schema::hasTable('stg_legacy_mass_upload_items')) {
            Schema::create('stg_legacy_mass_upload_items', function (Blueprint $table): void {
                $table->id();

                // Items are meaningless without their batch, so this is the one
                // place cascade is correct — it mirrors how
                // stg_legacy_patient_imports hangs off its batch.
                $table->foreignId('mass_upload_batch_id')
                    ->constrained('stg_legacy_mass_upload_batches')
                    ->cascadeOnDelete();

                // 1-based manifest data row (header excluded), so an operator
                // reading the report can find the line in their spreadsheet.
                $table->unsignedInteger('row_number');

                // As typed by the operator. Kept verbatim for audit; the
                // normalized form is stored separately so we never lose what
                // was actually submitted.
                $table->string('manifest_medical_record_number', 64);
                $table->string('manifest_file_name');

                // Raw date strings exactly as the manifest supplied them. They
                // are validated by the canonical date-rule service, not here,
                // and an invalid value must survive to the report unchanged.
                $table->string('manifest_selected_date', 32)->nullable();
                $table->string('manifest_latest_date', 32)->nullable();

                // Output of the canonical source-RM normalizer.
                $table->string('normalized_source_rm', 64)->nullable();

                // Server-resolved, never manifest-supplied.
                $table->foreignId('resolved_patient_id')
                    ->nullable()
                    ->constrained('mst_patients')
                    ->nullOnDelete();

                $table->foreignId('resolved_branch_id')
                    ->nullable()
                    ->constrained('mst_branches')
                    ->nullOnDelete();

                // Path of the clinical document INSIDE the extraction
                // workspace. Normalized and proven to sit under the workspace
                // root before it is ever written.
                $table->string('document_entry_path')->nullable();
                $table->string('document_sha256', 64)->nullable()->index();
                $table->unsignedBigInteger('document_bytes')->nullable();

                $table->string('status', 40)->index();

                // Stable machine reason (LegacyMassUploadReason) plus a message
                // safe to show an operator. No SQL, no stack trace, no path.
                $table->string('reason_code', 64)->nullable()->index();
                $table->text('reason_message')->nullable();

                // The canonical import this item produced, if any. Non-null is
                // the idempotency marker: a resumed dispatch skips it rather
                // than creating a second lifecycle for the same patient.
                $table->foreignId('rme_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_rme_legacy_imports')
                    ->nullOnDelete();

                $table->foreignId('odontogram_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_odontogram_legacy_imports')
                    ->nullOnDelete();

                $table->timestamp('dispatched_at')->nullable();

                $table->timestamps();

                // One row per manifest line.
                $table->unique(['mass_upload_batch_id', 'row_number'], 'stg_lmu_items_batch_row_unique');

                // One clinical file may be claimed by exactly one row in a
                // batch. This is what makes "duplicate file mapping" a
                // detectable PACKAGE failure instead of a silent last-one-wins.
                $table->unique(['mass_upload_batch_id', 'manifest_file_name'], 'stg_lmu_items_batch_file_unique');

                $table->index(['mass_upload_batch_id', 'status'], 'stg_lmu_items_batch_status_index');
                $table->index(['resolved_patient_id', 'status'], 'stg_lmu_items_patient_status_index');
            });
        }
    }

    public function down(): void
    {
        // Drops ONLY what this migration created. Nothing pre-existing is
        // touched, so a rollback cannot take a clinical table with it.
        Schema::dropIfExists('stg_legacy_mass_upload_items');
        Schema::dropIfExists('stg_legacy_mass_upload_batches');
    }
};
