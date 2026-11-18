<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR6 (rule 240 §8) — the handwriting
 * amendment page. Additive only (CG-09); no existing row is touched.
 *
 * `trx_rme_amendments` — the pending correction page a sheet amendment carries:
 *  - `correction_page_path`: the object key on the private clinical disk, chosen
 *    by the server (fresh random suffix), never by the client;
 *  - `correction_page_sha256`: the SHA-256 of the stored bytes, verified on every
 *    read and before the page is appended;
 *  - `corrects_page_number`: the page of the patient's book it corrects (optional).
 * They are part of the REQUEST: written with the row and never changed. The row
 * guard `trx_rme_amendments_guard()` (rule 240 §6.1) is replaced with the same
 * function comparing these three columns too — exactly what this PR needs and
 * nothing more: a pending row may still be cancelled, rejected or applied, and
 * nothing else about the request may change. down() restores the PR4 body.
 *
 * `trx_medical_record_handwriting_pages.rme_amendment_id` — the amendment whose
 * approval appended the page (pages 2+ only: an amendment page is always the
 * book's NEXT page, so page 1 never is one). Unique (one page per amendment),
 * restrict on delete; an amendment page is sealed (PostgreSQL CHECK).
 *
 * Explicit constraint names (≤ 63 characters). The PostgreSQL CHECKs:
 *  - `trx_rme_amd_correction_page_chk`: key and SHA-256 together, the SHA-256 is
 *    64 lowercase hex characters, a corrected page only with a correction page and ≥ 1;
 *  - `trx_rme_amd_correction_subject_chk`: only a sheet amendment carries one;
 *  - `trx_mrhp_rme_amendment_sealed_chk`: an amendment page is sealed.
 */
return new class extends Migration
{
    private const AMENDMENTS = 'trx_rme_amendments';

    private const PAGES = 'trx_medical_record_handwriting_pages';

    public function up(): void
    {
        Schema::table(self::AMENDMENTS, function (Blueprint $table) {
            $table->string('correction_page_path')->nullable();
            $table->string('correction_page_sha256', 64)->nullable();
            $table->unsignedInteger('corrects_page_number')->nullable();
        });

        Schema::table(self::PAGES, function (Blueprint $table) {
            $table->foreignId('rme_amendment_id')->nullable()
                ->constrained(self::AMENDMENTS, 'id', 'trx_mrhp_rme_amendment_fk')->restrictOnDelete();
            $table->unique('rme_amendment_id', 'trx_mrhp_rme_amendment_uq');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::AMENDMENTS." ADD CONSTRAINT trx_rme_amd_correction_page_chk CHECK (
            (correction_page_path IS NULL) = (correction_page_sha256 IS NULL)
            AND (correction_page_sha256 IS NULL OR correction_page_sha256 ~ '^[0-9a-f]{64}$')
            AND (corrects_page_number IS NULL OR (correction_page_path IS NOT NULL AND corrects_page_number >= 1))
        )");
        DB::statement('ALTER TABLE '.self::AMENDMENTS." ADD CONSTRAINT trx_rme_amd_correction_subject_chk CHECK (correction_page_path IS NULL OR subject_type = 'medical_record')");
        DB::statement('ALTER TABLE '.self::PAGES.' ADD CONSTRAINT trx_mrhp_rme_amendment_sealed_chk CHECK (rme_amendment_id IS NULL OR sealed_at IS NOT NULL)');

        DB::statement($this->guardFunction(withCorrectionPage: true));
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::PAGES, 'rme_amendment_id') && DB::table(self::PAGES)->whereNotNull('rme_amendment_id')->exists()) {
            throw new RuntimeException('Refusing to drop the amendment link of handwriting pages: amendment pages exist.');
        }

        if (Schema::hasColumn(self::AMENDMENTS, 'correction_page_path') && DB::table(self::AMENDMENTS)->whereNotNull('correction_page_path')->exists()) {
            throw new RuntimeException('Refusing to drop the correction page of RME amendments: amendments carrying one exist.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement($this->guardFunction(withCorrectionPage: false));
            DB::statement('ALTER TABLE '.self::PAGES.' DROP CONSTRAINT IF EXISTS trx_mrhp_rme_amendment_sealed_chk');
            DB::statement('ALTER TABLE '.self::AMENDMENTS.' DROP CONSTRAINT IF EXISTS trx_rme_amd_correction_subject_chk');
            DB::statement('ALTER TABLE '.self::AMENDMENTS.' DROP CONSTRAINT IF EXISTS trx_rme_amd_correction_page_chk');
        }

        Schema::table(self::PAGES, function (Blueprint $table) {
            $table->dropUnique('trx_mrhp_rme_amendment_uq');
            $table->dropForeign('trx_mrhp_rme_amendment_fk');
            $table->dropColumn('rme_amendment_id');
        });

        Schema::table(self::AMENDMENTS, function (Blueprint $table) {
            $table->dropColumn(['correction_page_path', 'correction_page_sha256', 'corrects_page_number']);
        });
    }

    /**
     * The rule 240 §6.1 row guard; with the correction page its three columns are
     * request columns too. The body is otherwise the one migration
     * 2026_11_17_400001 created.
     */
    private function guardFunction(bool $withCorrectionPage): string
    {
        $correction = $withCorrectionPage ? <<<'SQL'

                    OR NEW.correction_page_path IS DISTINCT FROM OLD.correction_page_path
                    OR NEW.correction_page_sha256 IS DISTINCT FROM OLD.correction_page_sha256
                    OR NEW.corrects_page_number IS DISTINCT FROM OLD.corrects_page_number
            SQL : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION trx_rme_amendments_guard() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'trx_rme_amendments rows are immutable (rule 240 §6): % refused', TG_OP;
                END IF;
                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'trx_rme_amendments row % is % and immutable (rule 240 §6)', OLD.id, OLD.status;
                END IF;
                IF NEW.subject_type IS DISTINCT FROM OLD.subject_type
                    OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.clinic_visit_id IS DISTINCT FROM OLD.clinic_visit_id
                    OR NEW.base_version_id IS DISTINCT FROM OLD.base_version_id
                    OR NEW.base_version IS DISTINCT FROM OLD.base_version
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.proposed_content::text IS DISTINCT FROM OLD.proposed_content::text
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at{$correction} THEN
                    RAISE EXCEPTION 'trx_rme_amendments row %: the request is immutable (rule 240 §6)', OLD.id;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
            SQL;
    }
};
