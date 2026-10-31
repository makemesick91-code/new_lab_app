<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — the append-only event
| history of a refund request: one row per transition. Metadata carries ids, cents
| and codes only — never names, NIK, notes or references (migration plan §3).
| No updated_at; the model refuses update and delete; down() refuses with rows.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_refund_request_events';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_refund_request_id')->constrained('trx_rme_refund_requests', 'id', 'trx_rme_refund_evt_request_fk')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->constrained('users', 'id', 'trx_rme_refund_evt_actor_fk')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rme_refund_request_id', 'occurred_at'], 'trx_rme_refund_evt_request_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_refund_request_events ADD CONSTRAINT trx_rme_refund_evt_to_status_chk CHECK (to_status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_refund_request_events: it holds refund history.');
        }

        Schema::dropIfExists(self::TABLE);
    }
};
