<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RME patient data format — identity and contact readiness fields.
 * Additive only; legacy rows remain valid with null values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('mst_patients', 'ktp_number')) {
                $table->string('ktp_number', 16)->nullable()->unique()->after('manual_rm_number');
            }

            if (! Schema::hasColumn('mst_patients', 'whatsapp_number')) {
                $table->string('whatsapp_number', 50)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('mst_patients', 'email')) {
                $table->string('email', 150)->nullable()->after('whatsapp_number');
            }

            if (! Schema::hasColumn('mst_patients', 'occupation')) {
                $table->string('occupation', 150)->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            foreach (['ktp_number', 'whatsapp_number', 'email', 'occupation'] as $column) {
                if (Schema::hasColumn('mst_patients', $column)) {
                    if ($column === 'ktp_number') {
                        $table->dropUnique(['ktp_number']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
