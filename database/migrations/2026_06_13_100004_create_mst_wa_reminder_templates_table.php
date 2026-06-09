<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 Phase 5 — mst_wa_reminder_templates. Global WA reminder template master data.
 * Not branch-scoped; shared across all branches. No WhatsApp API integration at this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_wa_reminder_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('trigger_type', 50);
            $table->string('audience_type', 50);
            $table->text('message_body');
            $table->json('available_variables')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('trigger_type');
            $table->index('audience_type');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_wa_reminder_templates');
    }
};
