<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-7-7, rule 153 amended) — additive only.
 *
 * The in-flight window refuses a second login while the first is still being
 * written. A double tap in ONE browser sends two logins posting the same page's
 * CSRF token (proven by VerifyCsrfToken to be that browser's pre-login session
 * token); without telling the two apart, the browser's own
 * second POST was refused and the doctor was locked out for the session
 * lifetime. The lease records a SHA-256 digest of that token (never the token)
 * so a claimant presenting the same one is recognised as the same browser.
 * Nullable: leases claimed before this column existed simply never match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('trx_doctor_session_leases', 'claim_nonce_hash')) {
            return;
        }

        Schema::table('trx_doctor_session_leases', function (Blueprint $table) {
            $table->string('claim_nonce_hash', 64)->nullable()->after('session_token_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('trx_doctor_session_leases', 'claim_nonce_hash')) {
            return;
        }

        Schema::table('trx_doctor_session_leases', function (Blueprint $table) {
            $table->dropColumn('claim_nonce_hash');
        });
    }
};
