<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR2 (rule 240 §4) — additive only.
 *
 * A handwriting page is sealed when a sheet of the patient is finalized; a sealed
 * page is never overwritten. Page 1 lives in `trx_medical_record_handwritings`,
 * pages 2+ in `trx_medical_record_handwriting_pages`; both get the same columns:
 *
 *  - `authored_clinic_visit_id` — the encounter that last wrote the page. Pages
 *    are stored on the BOOK's visit (R7-1), so `clinic_visit_id` cannot say which
 *    encounter wrote them.
 *  - `sealed_at` — the technical instant (UTC) of the seal.
 *  - `sealed_by_medical_record_id` — the final sheet whose finalize sealed it.
 *
 * All nullable, no backfill, no existing row touched: a page written before this
 * migration has neither stamp and is judged by the derived rule in rule 240 §4.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['trx_medical_record_handwritings', 'trx_medical_record_handwriting_pages'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasColumn($tableName, 'sealed_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('authored_clinic_visit_id')->nullable()
                    ->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
                $table->timestamp('sealed_at')->nullable();
                $table->foreignId('sealed_by_medical_record_id')->nullable()
                    ->constrained('trx_medical_records')->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasColumn($tableName, 'sealed_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('sealed_by_medical_record_id');
                $table->dropColumn('sealed_at');
                $table->dropConstrainedForeignId('authored_clinic_visit_id');
            });
        }
    }
};
