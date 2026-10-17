<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 (owner D21) — price PROVENANCE of an
 * RME invoice line.
 *
 * Every line created from this release records HOW its price was decided:
 *
 *   pricing_mode            TRANSITIONAL | STRICT_ENFORCED — the branch mode at billing time
 *   price_source            TARIFF_MATCHED | TARIFF_EXCEPTION_AUTHORIZED |
 *                           TRANSITIONAL_TARIFF_DEVIATION | TRANSITIONAL_NO_TARIFF |
 *                           TRANSITIONAL_FREE_TEXT
 *   tariff_id               the tariff in force that was compared against (if any)
 *   tariff_unit_price       that tariff's price AT BILLING TIME — the evidence. A
 *                           later tariff edit never rewrites what was compared.
 *   price_exception_reason  the written reason of an AUTHORIZED strict exception
 *   price_exception_by      the account that authorized it
 *
 * ADDITIVE ONLY. Every column is nullable and NOTHING IS BACKFILLED: a line
 * billed before this release keeps NULL, which reads "provenance not recorded
 * (before tariff authority)". Inventing a provenance for history would turn an
 * unknown into a false fact. Never migrate:fresh / db:wipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_rme_invoice_items', function (Blueprint $table) {
            $table->string('pricing_mode', 24)->nullable()->after('doctor_id');
            $table->string('price_source', 40)->nullable()->after('pricing_mode');
            $table->foreignId('tariff_id')->nullable()->after('price_source')
                ->constrained('mst_tariffs')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('tariff_unit_price', 18, 2)->nullable()->after('tariff_id'); // = mst_tariffs.price precision
            $table->text('price_exception_reason')->nullable()->after('tariff_unit_price');
            $table->foreignId('price_exception_by')->nullable()->after('price_exception_reason')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trx_rme_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_exception_by');
            $table->dropColumn('price_exception_reason');
            $table->dropColumn('tariff_unit_price');
            $table->dropConstrainedForeignId('tariff_id');
            $table->dropColumn('price_source');
            $table->dropColumn('pricing_mode');
        });
    }
};
