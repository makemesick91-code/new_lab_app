<?php

// SATUSEHAT-1 — Local entity ↔ SATUSEHAT/IHS identifier registry.
// Additive only. Patient/Practitioner/Organization/Location identifiers, kept
// strictly separate per environment. One ACTIVE identifier per
// (environment, entity_type, local_entity_type, local_entity_id) — PG partial
// unique + service-level enforcement. No external lookup is ever performed;
// identifiers are entered/verified administratively in SATUSEHAT-1.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_satusehat_entity_identifiers', function (Blueprint $table) {
            $table->id();

            $table->string('environment', 20)->default('sandbox');

            // FHIR resource type this identifier belongs to.
            $table->string('entity_type', 40);                 // Patient | Practitioner | Organization | Location
            // Local model reference.
            $table->string('local_entity_type', 60);           // patient | doctor | branch | clinic_room
            $table->unsignedBigInteger('local_entity_id');

            // The remote IHS/SATUSEHAT identifier value + optional system URL.
            $table->string('remote_identifier', 191);
            $table->string('identifier_system', 191)->nullable();

            $table->string('status', 20)->default('active');   // draft | active | inactive
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // Safe, non-sensitive provenance metadata only (never raw OAuth/API payloads).
            $table->json('source_metadata')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['environment', 'entity_type', 'status']);
            $table->index(['local_entity_type', 'local_entity_id']);
            $table->index('status');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX mst_satusehat_entity_identifiers_active_unique
                 ON mst_satusehat_entity_identifiers
                    (environment, entity_type, local_entity_type, local_entity_id)
                 WHERE status = 'active' AND deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_satusehat_entity_identifiers');
    }
};
