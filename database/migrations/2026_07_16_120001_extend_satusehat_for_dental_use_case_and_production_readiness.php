<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-3 — Dental Use-Case Expansion & Production Readiness.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 *
 * 1. mst_satusehat_code_mappings gains terminology-governance provenance:
 *    profile_family (e.g. "dental"), the OFFICIAL source citation + version,
 *    a human verification stamp (verified_at/verified_by), an optional
 *    effective_to window end (effective_date remains the window start), and a
 *    mapping_confidence label. A mapping in a profile family can only be
 *    ACTIVATED after it carries an official source AND a verification stamp
 *    (enforced in SatusehatMappingService — defense in depth with tests).
 *
 * 2. trx_satusehat_candidates gains the DENTAL readiness axis: a separate
 *    dental readiness status/reasons pair, a dental-only source hash (odontogram
 *    structured state + dental mapping versions), the hash pinned at approval so
 *    post-approval dental drift revokes the approval, an evaluation timestamp,
 *    and a PII-free coverage snapshot for the review UI.
 *
 * No column is dropped, renamed, or made NOT NULL. Legacy rows keep NULLs and
 * keep working — the dental axis is lazily computed on the next refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_satusehat_code_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'profile_family')) {
                $table->string('profile_family', 40)->nullable()->after('local_code')->index();
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'official_source')) {
                // Official documentation citation (URL / document title). Required
                // before a profile-family mapping may be activated.
                $table->string('official_source', 500)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'official_source_version')) {
                $table->string('official_source_version', 100)->nullable()->after('official_source');
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('official_source_version');
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('verified_at')
                    ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_date');
            }
            if (! Schema::hasColumn('mst_satusehat_code_mappings', 'mapping_confidence')) {
                // verified_official | snippet_needs_verification | unverified
                $table->string('mapping_confidence', 40)->nullable()->after('effective_to');
            }
        });

        Schema::table('trx_satusehat_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_satusehat_candidates', 'dental_readiness_status')) {
                // dental_ready | dental_incomplete | dental_mapping_blocked |
                // dental_unsupported | dental_source_changed | dental_conformance_failed
                $table->string('dental_readiness_status', 40)->nullable()->after('readiness_reasons');
                $table->index(['branch_id', 'dental_readiness_status'], 'trx_ss_candidates_branch_dental_idx');
            }
            if (! Schema::hasColumn('trx_satusehat_candidates', 'dental_readiness_reasons')) {
                $table->json('dental_readiness_reasons')->nullable()->after('dental_readiness_status');
            }
            if (! Schema::hasColumn('trx_satusehat_candidates', 'dental_source_hash')) {
                $table->string('dental_source_hash', 64)->nullable()->after('dental_readiness_reasons');
            }
            if (! Schema::hasColumn('trx_satusehat_candidates', 'approved_dental_source_hash')) {
                $table->string('approved_dental_source_hash', 64)->nullable()->after('dental_source_hash');
            }
            if (! Schema::hasColumn('trx_satusehat_candidates', 'dental_evaluated_at')) {
                $table->timestamp('dental_evaluated_at')->nullable()->after('approved_dental_source_hash');
            }
            if (! Schema::hasColumn('trx_satusehat_candidates', 'dental_coverage_snapshot')) {
                // PII-free (variable keys + statuses + reason codes only).
                $table->json('dental_coverage_snapshot')->nullable()->after('dental_evaluated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_satusehat_candidates', function (Blueprint $table) {
            foreach ([
                'dental_coverage_snapshot', 'dental_evaluated_at', 'approved_dental_source_hash',
                'dental_source_hash', 'dental_readiness_reasons',
            ] as $column) {
                if (Schema::hasColumn('trx_satusehat_candidates', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('trx_satusehat_candidates', 'dental_readiness_status')) {
                $table->dropIndex('trx_ss_candidates_branch_dental_idx');
                $table->dropColumn('dental_readiness_status');
            }
        });

        Schema::table('mst_satusehat_code_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('mst_satusehat_code_mappings', 'verified_by')) {
                $table->dropConstrainedForeignId('verified_by');
            }
            foreach ([
                'mapping_confidence', 'effective_to', 'verified_at',
                'official_source_version', 'official_source',
            ] as $column) {
                if (Schema::hasColumn('mst_satusehat_code_mappings', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('mst_satusehat_code_mappings', 'profile_family')) {
                $table->dropColumn('profile_family');
            }
        });
    }
};
