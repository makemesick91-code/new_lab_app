<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->foreignId('finalized_by')
                ->nullable()
                ->after('finalized_at')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->dropForeignIdFor(User::class, 'finalized_by');
            $table->dropColumn('finalized_by');
        });
    }
};
