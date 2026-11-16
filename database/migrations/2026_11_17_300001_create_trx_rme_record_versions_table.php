<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR3 (rule 240 §5) — the version
 * history of a final sheet and of a finalized odontogram.
 *
 * One immutable row per version of a subject (`medical_record` or
 * `odontogram`, by id): version 1 is written by finalize (`final`) or, for a
 * record finalized before rule 240, by the first amendment (`baseline`); later
 * versions are amendments (`amendment`, M3.3 PR5). Each row carries a JSON
 * snapshot and a SHA-256 hash chained to the previous version's hash over a
 * canonical JSON encoding, so any edit of a stored row is detectable.
 *
 * Rows are never updated or deleted: the model refuses, and on PostgreSQL a
 * trigger refuses a raw UPDATE or DELETE. No `updated_at`. The "Diamandemen n×"
 * print marker is the count of `amendment` rows of the subject, so no marker
 * column is added (rule 240 §5). Additive only (CG-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_record_versions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('version');
            $table->string('kind', 16);
            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->jsonb('snapshot');
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['subject_type', 'subject_id', 'version']);
            $table->index(['subject_type', 'subject_id', 'kind']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE trx_rme_record_versions ADD CONSTRAINT trx_rme_record_versions_subject_chk CHECK (subject_type IN ('medical_record', 'odontogram'))");
        DB::statement("ALTER TABLE trx_rme_record_versions ADD CONSTRAINT trx_rme_record_versions_kind_chk CHECK (kind IN ('final', 'baseline', 'amendment'))");
        DB::statement('ALTER TABLE trx_rme_record_versions ADD CONSTRAINT trx_rme_record_versions_version_chk CHECK (version >= 1)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trx_rme_record_versions_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'trx_rme_record_versions rows are immutable (rule 240 §5): % refused', TG_OP;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER trx_rme_record_versions_immutable BEFORE UPDATE OR DELETE ON trx_rme_record_versions FOR EACH ROW EXECUTE FUNCTION trx_rme_record_versions_immutable()');
    }

    public function down(): void
    {
        if (Schema::hasTable('trx_rme_record_versions') && DB::table('trx_rme_record_versions')->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_record_versions: medical-record versions exist (they are the only copy of a record before an amendment).');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trx_rme_record_versions_immutable ON trx_rme_record_versions');
            DB::statement('DROP FUNCTION IF EXISTS trx_rme_record_versions_immutable()');
        }

        Schema::dropIfExists('trx_rme_record_versions');
    }
};
