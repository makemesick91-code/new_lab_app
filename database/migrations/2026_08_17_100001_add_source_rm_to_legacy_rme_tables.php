<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — the Nomor RM PRINTED ON THE SOURCE DOCUMENT
 * becomes first-class structured evidence.
 *
 * ADDITIVE ONLY. No column is dropped, narrowed or rewritten, and nothing is
 * backfilled.
 *
 * WHY. Until now the only RM the archive stored was the SELECTED patient's own
 * canonical Nomor RM. The number actually printed on the paper lived in the PDF
 * pixels and in the operator's short-term memory, so the server had nothing to
 * compare and could not tell "this document belongs to this patient" from "the
 * operator picked the wrong row". LEGACY-RME-MASTERDATA-1 recorded that gap
 * explicitly; this migration closes it by giving the assertion a column.
 *
 * TWO VALUES, DELIBERATELY. `source_rm_raw` is what the human confirmed they
 * read on the document, kept verbatim — it is the evidence, and normalizing it
 * away would destroy the only record of what the paper said. `source_rm_normalized`
 * is the canonical form the resolver actually matched on. Keeping both means a
 * later audit can tell a transcription style from an identity change.
 *
 * NULLABLE, AND THAT IS NOT A WEAKNESS. Rows created before this sprint have no
 * captured source RM and never will: nobody re-read those documents. Inventing a
 * value for them — copying the selected patient's RM, say — would manufacture
 * evidence that an independent confirmation happened when it did not. So the
 * columns stay nullable at the database level for history, while the application
 * write path REQUIRES them for every new import, and review/publish refuse a
 * non-terminal pre-enforcement row rather than waving it through.
 *
 * ON THE PUBLISHED RECORD TOO. `trx_rme_legacy_records` already copies the
 * source identity evidence it must be able to answer for on its own
 * (`source_pdf_sha256`, `normalized_content_hash`) rather than joining a
 * soft-deletable staging row. The asserted source RM is the same class of
 * evidence — "what patient did this document claim to be about?" — so it is
 * carried the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_rme_legacy_imports', function (Blueprint $table) {
            // Verbatim, as the operator confirmed reading it on the document.
            $table->string('source_rm_raw', 64)->nullable()->after('earliest_native_rme_date_snapshot');

            // The canonical form the identity resolver matched on.
            $table->string('source_rm_normalized', 64)->nullable()->after('source_rm_raw');

            // The LegacyRmePatientResolution code the binding was accepted on
            // (EXACT_UNIQUE / SEGMENT_UNIQUE). Evidence of HOW identity was
            // established, so an audit never has to re-derive it.
            $table->string('source_rm_resolution', 32)->nullable()->after('source_rm_normalized');

            $table->index('source_rm_normalized', 'stg_rme_legacy_imports_source_rm_index');
        });

        Schema::table('trx_rme_legacy_records', function (Blueprint $table) {
            $table->string('source_rm_raw', 64)->nullable()->after('normalized_content_hash');
            $table->string('source_rm_normalized', 64)->nullable()->after('source_rm_raw');

            $table->index('source_rm_normalized', 'trx_rme_legacy_records_source_rm_index');
        });
    }

    public function down(): void
    {
        Schema::table('trx_rme_legacy_records', function (Blueprint $table) {
            $table->dropIndex('trx_rme_legacy_records_source_rm_index');
            $table->dropColumn(['source_rm_raw', 'source_rm_normalized']);
        });

        Schema::table('stg_rme_legacy_imports', function (Blueprint $table) {
            $table->dropIndex('stg_rme_legacy_imports_source_rm_index');
            $table->dropColumn(['source_rm_raw', 'source_rm_normalized', 'source_rm_resolution']);
        });
    }
};
