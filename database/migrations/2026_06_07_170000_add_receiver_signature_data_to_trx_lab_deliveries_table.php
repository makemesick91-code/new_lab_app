<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.4 Step 6 — manual canvas signature stored as text (base64 PNG data URL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_lab_deliveries', function (Blueprint $table) {
            $table->longText('receiver_signature_data')->nullable()->after('receiver_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('trx_lab_deliveries', function (Blueprint $table) {
            $table->dropColumn('receiver_signature_data');
        });
    }
};
