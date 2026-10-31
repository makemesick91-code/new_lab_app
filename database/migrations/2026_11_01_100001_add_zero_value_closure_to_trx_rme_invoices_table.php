<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (Sprint 6/7, rule 217; owner decision
| S6-D2) — "Selesai Tanpa Biaya", additively:
|
|   trx_rme_invoices + zero_value_category   CONSULTATION | CONTROL
|                    + zero_value_closed_by  FK users (RESTRICT)
|                    + zero_value_closed_at
|
| The status column is a plain string with no CHECK; the new terminal status
| NO_CHARGE needs no widening. Three PostgreSQL CHECKs bind the new columns to it:
|   - NO_CHARGE ⇔ all three closure columns are set (no half-closed row, and no
|     provenance on a row that was never closed);
|   - NO_CHARGE ⇒ grand_total = 0 (a charged invoice can never be closed free);
|   - the category vocabulary.
|
| No existing row changes value (production 2026-11-01: 9 invoices, none
| NO_CHARGE; the historical Rp0 invoice #36 stays UNPAID by owner decision); no
| column is dropped or narrowed. down() refuses while a NO_CHARGE invoice exists,
| because dropping the provenance of a closed invoice would erase why it is free.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_invoices';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('zero_value_category', 20)->nullable();
            $table->foreignId('zero_value_closed_by')->nullable()
                ->constrained('users', 'id', 'trx_rme_invoices_zero_value_closed_by_fk')
                ->restrictOnDelete();
            $table->timestampTz('zero_value_closed_at')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT trx_rme_invoices_no_charge_chk CHECK ('
            ."(status = 'NO_CHARGE') = (zero_value_category IS NOT NULL AND zero_value_closed_by IS NOT NULL AND zero_value_closed_at IS NOT NULL))");
        DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT trx_rme_invoices_no_charge_zero_chk CHECK (status <> 'NO_CHARGE' OR grand_total = 0)");
        DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT trx_rme_invoices_zero_value_category_chk CHECK (zero_value_category IS NULL OR zero_value_category IN ('CONSULTATION','CONTROL'))");
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->where('status', 'NO_CHARGE')->exists()) {
            throw new RuntimeException('Cannot roll back: NO_CHARGE invoices exist. Their closure provenance is a financial record and is never dropped.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_invoices_zero_value_category_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_invoices_no_charge_zero_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_invoices_no_charge_chk');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropForeign('trx_rme_invoices_zero_value_closed_by_fk');
            $table->dropColumn(['zero_value_category', 'zero_value_closed_by', 'zero_value_closed_at']);
        });
    }
};
