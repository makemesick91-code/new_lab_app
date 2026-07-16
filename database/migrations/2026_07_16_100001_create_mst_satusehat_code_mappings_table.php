<?php

// SATUSEHAT-1 — Versioned, auditable local→SATUSEHAT code/resource mapping.
// Additive only. One ACTIVE mapping per (environment, local_entity_type,
// local key, target_resource_type) is enforced by a PG partial unique index
// AND by the mapping service (SQLite has no partial unique in tests).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_satusehat_code_mappings', function (Blueprint $table) {
            $table->id();

            // Sandbox/production are never mixed.
            $table->string('environment', 20)->default('sandbox');

            // Local side: either an entity reference (local_entity_type + id) or
            // a local code (e.g. a treatment/diagnosis code string).
            $table->string('local_entity_type', 60);          // e.g. treatment, diagnosis
            $table->unsignedBigInteger('local_entity_id')->nullable();
            $table->string('local_code', 100)->nullable();

            // Target FHIR side.
            $table->string('target_resource_type', 60);        // Encounter | Condition | Procedure ...
            $table->string('target_path', 191)->nullable();    // FHIR element path (informational)
            $table->string('terminology_system', 191)->nullable(); // e.g. http://hl7.org/fhir/sid/icd-10
            $table->string('target_code', 100)->nullable();
            $table->string('target_display', 191)->nullable();

            $table->date('effective_date')->nullable();

            // draft → active → deprecated (mappings are versioned, never edited in place once active).
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['environment', 'local_entity_type', 'status']);
            $table->index(['local_entity_type', 'local_entity_id']);
            $table->index('local_code');
            $table->index('target_resource_type');
            $table->index('status');
        });

        // One ACTIVE mapping per logical key. PG-only; the service enforces the
        // same invariant for SQLite (tests) and as defense in depth.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX mst_satusehat_code_mappings_active_unique
                 ON mst_satusehat_code_mappings
                    (environment, local_entity_type, COALESCE(local_entity_id, 0),
                     COALESCE(local_code, ''), target_resource_type)
                 WHERE status = 'active' AND deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_satusehat_code_mappings');
    }
};
