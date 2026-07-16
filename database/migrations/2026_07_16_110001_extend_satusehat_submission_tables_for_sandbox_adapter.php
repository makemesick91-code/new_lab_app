<?php

// SATUSEHAT-2 — Additive extension of the submission batch/item tables for the
// real sandbox adapter: correlation ids, request/response hashes, remote version,
// HTTP status, sanitized OperationOutcome, outcome classification, retry/lock
// scheduling, unknown-outcome + reconciliation metadata. Additive + widening
// only — no column dropped, no destructive change. The existing UNIQUE on
// trx_satusehat_submission_items.idempotency_key is the DB-level duplicate guard.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_satusehat_submission_items', function (Blueprint $table) {
            // Widen status to fit the SATUSEHAT-2 states (e.g. reconciliation_required).
            $table->string('status', 40)->default('pending')->change();

            if (! Schema::hasColumn('trx_satusehat_submission_items', 'operation_type')) {
                $table->string('operation_type', 20)->nullable()->after('resource_type');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'correlation_id')) {
                $table->string('correlation_id', 191)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'request_payload_hash')) {
                $table->string('request_payload_hash', 64)->nullable()->after('payload_hash');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'response_hash')) {
                $table->string('response_hash', 64)->nullable()->after('request_payload_hash');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'remote_version_id')) {
                $table->string('remote_version_id', 191)->nullable()->after('remote_resource_id');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable()->after('remote_version_id');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'outcome_classification')) {
                $table->string('outcome_classification', 20)->nullable()->after('http_status');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'operation_outcome')) {
                // Sanitized OperationOutcome issues only — never a raw body/NIK.
                $table->json('operation_outcome')->nullable()->after('outcome_classification');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'reconciliation_note')) {
                $table->text('reconciliation_note')->nullable()->after('error_summary');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'next_attempt_at')) {
                $table->timestamp('next_attempt_at')->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'locked_at')) {
                $table->timestamp('locked_at')->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'locked_by')) {
                $table->string('locked_by', 191)->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_submission_items', 'reconciled_at')) {
                $table->timestamp('reconciled_at')->nullable();
            }

            $table->index('outcome_classification', 'ss_items_outcome_idx');
            $table->index(['status', 'next_attempt_at'], 'ss_items_status_next_idx');
        });

        Schema::table('trx_satusehat_submission_batches', function (Blueprint $table) {
            $table->string('status', 40)->default('draft')->change();

            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'correlation_id')) {
                $table->string('correlation_id', 191)->nullable()->after('status');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('prepared_at');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'succeeded_count')) {
                $table->unsignedInteger('succeeded_count')->default(0)->after('resource_count');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'failed_count')) {
                $table->unsignedInteger('failed_count')->default(0)->after('succeeded_count');
            }
            if (! Schema::hasColumn('trx_satusehat_submission_batches', 'unknown_count')) {
                $table->unsignedInteger('unknown_count')->default(0)->after('failed_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_satusehat_submission_items', function (Blueprint $table) {
            $table->dropIndex('ss_items_outcome_idx');
            $table->dropIndex('ss_items_status_next_idx');
            $table->dropColumn([
                'operation_type', 'correlation_id', 'request_payload_hash', 'response_hash',
                'remote_version_id', 'http_status', 'outcome_classification', 'operation_outcome',
                'reconciliation_note', 'next_attempt_at', 'locked_at', 'locked_by',
                'submitted_at', 'reconciled_at',
            ]);
        });

        Schema::table('trx_satusehat_submission_batches', function (Blueprint $table) {
            $table->dropColumn([
                'correlation_id', 'started_at', 'completed_at',
                'succeeded_count', 'failed_count', 'unknown_count',
            ]);
        });
    }
};
