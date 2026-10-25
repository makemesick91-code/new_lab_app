<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — the durable record of
| ONE committed payment submission (one payment intent).
|--------------------------------------------------------------------------
|
| A row EXISTS IF AND ONLY IF the submission's payment effect committed: it is
| inserted by RmePaymentService inside the same transaction as the payment row(s)
| it describes, after they are written. A rollback therefore leaves neither a
| payment nor a row ("no ghost intent"), and a retry of the same intent after a
| rollback is evaluated afresh.
|
| There is deliberately NO status column. An "in progress" or "issued" state
| persisted outside the payment transaction could outlive a crash and either
| block a legitimate retry or describe money that was never recorded. The intent
| key is issued statelessly (server-signed, RmePaymentIntent); while a payment is
| in flight its lock and this table's UNIQUE(intent_key) serialize any duplicate.
|
| The UNIQUE(intent_key) index is the database backstop of "at most one payment
| effect per intent": even if two transactions ever reached the insert for the
| same intent, the second violates it and rolls back with its payment rows.
|
| Holds identifiers, the path, a SHA-256 fingerprint of the submitted form and
| the total amount — never a payment note, a reference number or patient data in
| plaintext (the fingerprint is a hash).
|
| Retention: kept forever, exactly like the payments it describes. Deleting a row
| would let a later replay of the same form record the payment a second time.
| down() therefore refuses while any committed submission exists.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('intent_key', 64)->unique('trx_rme_payment_submissions_intent_key_unique');
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('payment_path', 32);
            $table->char('request_fingerprint', 64);
            $table->decimal('amount', 15, 2);
            $table->foreignId('rme_payment_id')->constrained('trx_rme_payments')->cascadeOnUpdate()->restrictOnDelete();
            $table->uuid('payment_batch_uuid')->nullable();
            $table->unsignedSmallInteger('payment_count');
            $table->timestamp('committed_at');
            $table->timestamps();

            $table->index(['rme_invoice_id', 'committed_at'], 'trx_rme_payment_submissions_invoice_idx');
            $table->index(['cashier_id', 'committed_at'], 'trx_rme_payment_submissions_cashier_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_payment_submissions ADD CONSTRAINT trx_rme_payment_submission_path_chk CHECK (payment_path IN ('single', 'control', 'visit', 'control_only'))");
            DB::statement("ALTER TABLE trx_rme_payment_submissions ADD CONSTRAINT trx_rme_payment_submission_key_chk CHECK (intent_key ~ '^[0-9a-f]{32}$')");
            DB::statement("ALTER TABLE trx_rme_payment_submissions ADD CONSTRAINT trx_rme_payment_submission_fingerprint_chk CHECK (request_fingerprint ~ '^[0-9a-f]{64}$')");
            DB::statement('ALTER TABLE trx_rme_payment_submissions ADD CONSTRAINT trx_rme_payment_submission_amount_chk CHECK (amount > 0)');
            DB::statement('ALTER TABLE trx_rme_payment_submissions ADD CONSTRAINT trx_rme_payment_submission_count_chk CHECK (payment_count >= 1)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('trx_rme_payment_submissions')
            && DB::table('trx_rme_payment_submissions')->exists()) {
            throw new RuntimeException(
                'trx_rme_payment_submissions holds committed payment submissions. Dropping it would let a replayed '
                .'payment form record the same payment again. Roll forward instead (rule 205).',
            );
        }

        Schema::dropIfExists('trx_rme_payment_submissions');
    }
};
