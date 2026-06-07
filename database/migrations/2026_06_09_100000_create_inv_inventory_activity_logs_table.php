<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_inventory_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 100)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'created_at'], 'inv_activity_logs_branch_created_index');
            $table->index(['user_id', 'created_at'], 'inv_activity_logs_user_created_index');
            $table->index(['action', 'created_at'], 'inv_activity_logs_action_created_index');
            $table->index(['subject_type', 'subject_id'], 'inv_activity_logs_subject_index');
            $table->index('correlation_id', 'inv_activity_logs_correlation_index');
            $table->index('created_at', 'inv_activity_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_inventory_activity_logs');
    }
};
