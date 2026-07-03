<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // PostgreSQL only. SQLite (test DB) does not support CONCURRENTLY/INCLUDE
        // and its small in-memory datasets do not need it, so this is a no-op there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_rme_payments_rme_invoice_id_index
            ON trx_rme_payments (rme_invoice_id)
        ');

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_rme_invoices_active_receivable_order_idx
            ON trx_rme_invoices (branch_id, status, created_at DESC, id DESC)
            INCLUDE (grand_total, patient_id, clinic_visit_id, invoice_number)
            WHERE deleted_at IS NULL
              AND status IN ('UNPAID', 'PARTIAL')
              AND grand_total > 0
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            DROP INDEX CONCURRENTLY IF EXISTS trx_rme_invoices_active_receivable_order_idx
        ');

        DB::statement('
            DROP INDEX CONCURRENTLY IF EXISTS trx_rme_payments_rme_invoice_id_index
        ');
    }
};
