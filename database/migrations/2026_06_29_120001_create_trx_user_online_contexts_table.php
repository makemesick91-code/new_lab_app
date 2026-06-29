<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 66.0 — Doctor/Admin online context for RME presence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_user_online_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('mst_branches')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('clinic_room_id')->nullable()->constrained('mst_clinic_rooms')->cascadeOnUpdate()->nullOnDelete();
            $table->string('role_context', 32);
            $table->string('status', 16)->default('inactive');
            $table->timestamp('online_since')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('offline_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('branch_id');
            $table->index('clinic_room_id');
            $table->index('status');
            $table->index('role_context');
            $table->index('last_seen_at');
            $table->index(['branch_id', 'status', 'role_context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_user_online_contexts');
    }
};
