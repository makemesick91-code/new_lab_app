<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 (rule 240 §7) — the version an
 * applied amendment wrote.
 *
 * Approve-and-apply writes the next version of the record (kind `amendment`) and
 * records it on the amendment: `applied_version_id` (unique — one amendment per
 * version) and `applied_version` (its number). Both are set in the same
 * transition that makes the row `applied`, while it is still `pending`, so the
 * row guard of rule 240 §6.1 lets them through and keeps them fixed afterwards.
 * PostgreSQL CHECK: an `applied` row names its version and no other row does.
 * Additive only (CG-09).
 */
return new class extends Migration
{
    private const TABLE = 'trx_rme_amendments';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('applied_version_id')->nullable();
            $table->unsignedInteger('applied_version')->nullable();

            $table->unique('applied_version_id', 'trx_rme_amd_applied_version_uq');
        });

        // The foreign key is added on PostgreSQL only: on SQLite, adding one
        // rebuilds the table, and the rebuild would turn the partial unique index
        // `trx_rme_amd_one_pending_uq` (rule 240 §6.1) into a full one.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT trx_rme_amd_applied_version_fk FOREIGN KEY (applied_version_id) REFERENCES trx_rme_record_versions (id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT trx_rme_amd_applied_chk CHECK ((status = 'applied') = (applied_version_id IS NOT NULL AND applied_version IS NOT NULL))");
        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT trx_rme_amd_applied_version_chk CHECK (applied_version IS NULL OR applied_version >= 2)');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->whereNotNull('applied_version_id')->exists()) {
            throw new RuntimeException('Refusing to drop the applied version of RME amendments: applied amendments exist.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_amd_applied_version_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_amd_applied_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS trx_rme_amd_applied_version_fk');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique('trx_rme_amd_applied_version_uq');
            $table->dropColumn(['applied_version_id', 'applied_version']);
        });
    }
};
