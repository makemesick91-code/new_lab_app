<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216; RFS-4) — widen the refund
| request and the overpayment obligation by ONE state each, additively:
|
|   trx_rme_refund_requests         status += 'SETTLED'   (APPROVED → SETTLED)
|   trx_rme_refund_request_events   to_status += 'SETTLED' (its history records the edge)
|   trx_rme_overpayment_obligations status += 'SETTLED'   (OPEN → SETTLED), plus
|                                   settled_at + rme_refund_settlement_id, both null
|                                   exactly while OPEN.
|
| No existing row changes value (production 2026-10-31: 0 obligations, 0 requests);
| no column is dropped or narrowed. The settlement transaction moves request and
| obligation together with the settlement row.
|
| down() refuses while a SETTLED request or obligation exists, then restores the
| narrower CHECKs.
*/
return new class extends Migration
{
    private const REQUESTS = 'trx_rme_refund_requests';

    private const REQUEST_EVENTS = 'trx_rme_refund_request_events';

    private const OBLIGATIONS = 'trx_rme_overpayment_obligations';

    public function up(): void
    {
        Schema::table(self::OBLIGATIONS, function (Blueprint $table) {
            $table->timestampTz('settled_at')->nullable();
            $table->foreignId('rme_refund_settlement_id')->nullable()
                ->unique('trx_rme_ovp_settlement_uq')
                ->constrained('trx_rme_refund_settlements', 'id', 'trx_rme_ovp_settlement_fk')
                ->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::REQUESTS.' DROP CONSTRAINT IF EXISTS trx_rme_refund_req_status_chk');
        DB::statement('ALTER TABLE '.self::REQUESTS." ADD CONSTRAINT trx_rme_refund_req_status_chk CHECK (status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE','SETTLED'))");
        // A settled request was approved, by someone other than its maker.
        DB::statement('ALTER TABLE '.self::REQUESTS." ADD CONSTRAINT trx_rme_refund_req_settled_chk CHECK (status <> 'SETTLED' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL AND cancelled_by IS NULL))");
        // The request's append-only history records the APPROVED → SETTLED edge too.
        DB::statement('ALTER TABLE '.self::REQUEST_EVENTS.' DROP CONSTRAINT IF EXISTS trx_rme_refund_evt_to_status_chk');
        DB::statement('ALTER TABLE '.self::REQUEST_EVENTS." ADD CONSTRAINT trx_rme_refund_evt_to_status_chk CHECK (to_status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE','SETTLED'))");

        DB::statement('ALTER TABLE '.self::OBLIGATIONS.' DROP CONSTRAINT IF EXISTS trx_rme_ovp_status_chk');
        DB::statement('ALTER TABLE '.self::OBLIGATIONS." ADD CONSTRAINT trx_rme_ovp_status_chk CHECK (status IN ('OPEN','SETTLED'))");
        DB::statement('ALTER TABLE '.self::OBLIGATIONS.' ADD CONSTRAINT trx_rme_ovp_settled_chk CHECK ('
            ."(status = 'OPEN' AND settled_at IS NULL AND rme_refund_settlement_id IS NULL)"
            ." OR (status = 'SETTLED' AND settled_at IS NOT NULL AND rme_refund_settlement_id IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::table(self::REQUESTS)->where('status', 'SETTLED')->exists()
            || DB::table(self::REQUEST_EVENTS)->where('to_status', 'SETTLED')->exists()
            || DB::table(self::OBLIGATIONS)->where('status', 'SETTLED')->exists()) {
            throw new RuntimeException('Refusing to narrow refund statuses: a SETTLED request or obligation exists.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::OBLIGATIONS.' DROP CONSTRAINT IF EXISTS trx_rme_ovp_settled_chk');
            DB::statement('ALTER TABLE '.self::OBLIGATIONS.' DROP CONSTRAINT IF EXISTS trx_rme_ovp_status_chk');
            DB::statement('ALTER TABLE '.self::OBLIGATIONS." ADD CONSTRAINT trx_rme_ovp_status_chk CHECK (status IN ('OPEN'))");
            DB::statement('ALTER TABLE '.self::REQUEST_EVENTS.' DROP CONSTRAINT IF EXISTS trx_rme_refund_evt_to_status_chk');
            DB::statement('ALTER TABLE '.self::REQUEST_EVENTS." ADD CONSTRAINT trx_rme_refund_evt_to_status_chk CHECK (to_status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE'))");
            DB::statement('ALTER TABLE '.self::REQUESTS.' DROP CONSTRAINT IF EXISTS trx_rme_refund_req_settled_chk');
            DB::statement('ALTER TABLE '.self::REQUESTS.' DROP CONSTRAINT IF EXISTS trx_rme_refund_req_status_chk');
            DB::statement('ALTER TABLE '.self::REQUESTS." ADD CONSTRAINT trx_rme_refund_req_status_chk CHECK (status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE'))");
        }

        Schema::table(self::OBLIGATIONS, function (Blueprint $table) {
            $table->dropForeign('trx_rme_ovp_settlement_fk');
            $table->dropUnique('trx_rme_ovp_settlement_uq');
            $table->dropColumn(['settled_at', 'rme_refund_settlement_id']);
        });
    }
};
