<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| DMS memo M4.4, PTM plan PR3 (rule 241 §6) — consent template v2026.2 splits
| the 2026.1 clause 8 (documentation AND publication, one answer) into clause 8
| (clinical documentation, still `documentation_consent`) and clause 9
| (publication / promotion, this column).
|
| Additive and nullable: no existing row is updated. NULL means "this consent's
| version has no clause 9" — every historical consent keeps its version, its
| snapshot and its single clause-8 answer. The service writes this column only
| for a consent signed on a version that has a clause 9, and always as an explicit
| YA/TIDAK (never a default). No CHECK binds it to a version string: the
| snapshot is the authority on what was signed.
|
| down() refuses while any consent holds a clause-9 answer: dropping the column
| would erase what a patient answered on a signed legal document.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_visit_consents';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->boolean('publication_consent')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->whereNotNull('publication_consent')->exists()) {
            throw new RuntimeException('Cannot roll back: consents hold a clause-9 publication_consent answer. A signed consent answer is never dropped.');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('publication_consent');
        });
    }
};
