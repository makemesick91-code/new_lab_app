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
    /**
     * Table => short prefix for the constraint names. The generated names
     * (table_column_foreign) run to 64–72 characters, past PostgreSQL's 63, which
     * it truncates silently; explicit short names stay exact and greppable.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'trx_medical_record_handwritings' => 'trx_mrh',
        'trx_medical_record_handwriting_pages' => 'trx_mrhp',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $prefix) {
            if (Schema::hasColumn($tableName, 'sealed_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->foreignId('authored_clinic_visit_id')->nullable()
                    ->constrained('trx_clinic_visits', 'id', $prefix.'_authored_visit_fk')->cascadeOnUpdate()->restrictOnDelete();
                $table->timestamp('sealed_at')->nullable();
                $table->foreignId('sealed_by_medical_record_id')->nullable()
                    ->constrained('trx_medical_records', 'id', $prefix.'_sealed_by_record_fk')->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName => $prefix) {
            if (! Schema::hasColumn($tableName, 'sealed_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->dropForeign($prefix.'_sealed_by_record_fk');
                $table->dropColumn('sealed_by_medical_record_id');
                $table->dropColumn('sealed_at');
                $table->dropForeign($prefix.'_authored_visit_fk');
                $table->dropColumn('authored_clinic_visit_id');
            });
        }
    }
};
