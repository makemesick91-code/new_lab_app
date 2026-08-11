<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — persist the LATEST clinical date a legacy
 * document represents.
 *
 * WHY THIS HAS TO BE PERSISTED, not just validated in-flight.
 *
 * The representative date (`selected_rme_date` / `rme_date`) is the EARLIEST
 * date on the document. The safety rule, however, is about the LATEST one:
 * every date the document represents must precede the patient's earliest
 * native RME. Publishing re-runs that rule against a FRESHLY resolved cutoff,
 * because the patient's native history can change between upload and publish —
 * and it cannot re-check a range it does not have. Validating the latest date
 * only at staging time would leave the publish-time revalidation blind to
 * exactly the race it exists to catch.
 *
 * It is also the provenance record of what the operator attested to: the audit
 * trail must be able to show the declared range, not just its oldest end.
 *
 * ADDITIVE ONLY. Two nullable date columns; nothing is dropped, made NOT NULL,
 * renamed or backfilled. Existing rows keep NULL, which the domain reads as
 * "single-date document" (the range collapses to the representative date) —
 * the same meaning those rows already had. Run with `migrate`; never
 * `migrate:fresh` or `db:wipe`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_rme_legacy_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('stg_rme_legacy_imports', 'latest_rme_date')) {
                $table->date('latest_rme_date')
                    ->nullable()
                    ->after('selected_rme_date')
                    ->comment('LEGACY-RME-PDF-FIX-ROLL2-1: latest clinical date the document represents; NULL = single-date document.');
            }
        });

        Schema::table('trx_rme_legacy_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('trx_rme_legacy_records', 'latest_rme_date')) {
                $table->date('latest_rme_date')
                    ->nullable()
                    ->after('rme_date')
                    ->comment('LEGACY-RME-PDF-FIX-ROLL2-1: latest clinical date the document represents; NULL = single-date document.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stg_rme_legacy_imports', function (Blueprint $table): void {
            if (Schema::hasColumn('stg_rme_legacy_imports', 'latest_rme_date')) {
                $table->dropColumn('latest_rme_date');
            }
        });

        Schema::table('trx_rme_legacy_records', function (Blueprint $table): void {
            if (Schema::hasColumn('trx_rme_legacy_records', 'latest_rme_date')) {
                $table->dropColumn('latest_rme_date');
            }
        });
    }
};
