<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| DMS memo M4.4 / M1.x, PTM PR4 (rule 242; owner decisions 2026-11-17: plan
| defaults accepted, photos flag OFF) — clinical photos of an encounter.
|
| Additive only; no existing row is touched. One row per stored photo; the row
| and its private object (rule 121, ClinicalEvidenceStorage) are one unit
| (rule 224):
|
|  - clinic_visit_id / patient_id / branch_id   copied from the visit row locked
|                at upload (never the request), so a read is scoped without a join;
|  - rme_visit_consent_id   the EXECUTED consent whose clinical-documentation
|                answer allowed the upload;
|  - uploaded_by / uploaded_at   the account, and clinic time (ClinicalClock);
|  - photo_path   the private object key (UNIQUE, never serialised);
|  - mime_type / byte_size / width / height / sha256   the stored, re-encoded
|                JPEG (EXIF and GPS stripped);
|  - original_sha256   the SHA-256 of the uploaded original, which is NOT kept
|                (it could carry GPS) — plan open question 4, accepted;
|  - voided_at / voided_by / void_reason   correction by void, never delete
|                (the void path is its own PR).
|
| restrictOnDelete everywhere: evidence never vanishes with a parent row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_clinical_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')
                ->constrained('trx_clinic_visits', 'id', 'trx_rme_cp_visit_fk')
                ->restrictOnDelete();
            $table->foreignId('patient_id')
                ->constrained('mst_patients', 'id', 'trx_rme_cp_patient_fk')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('mst_branches', 'id', 'trx_rme_cp_branch_fk')
                ->restrictOnDelete();
            $table->foreignId('rme_visit_consent_id')
                ->constrained('trx_rme_visit_consents', 'id', 'trx_rme_cp_consent_fk')
                ->restrictOnDelete();
            $table->foreignId('uploaded_by')
                ->constrained('users', 'id', 'trx_rme_cp_uploaded_by_fk')
                ->restrictOnDelete();
            $table->string('photo_path');
            $table->string('mime_type', 32);
            $table->unsignedInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('sha256', 64);
            $table->string('original_sha256', 64);
            $table->timestamp('uploaded_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users', 'id', 'trx_rme_cp_voided_by_fk')
                ->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique('photo_path', 'trx_rme_cp_photo_path_uq');
            $table->index(['clinic_visit_id', 'uploaded_at'], 'trx_rme_cp_visit_idx');
            $table->index('patient_id', 'trx_rme_cp_patient_idx');
        });
    }

    public function down(): void
    {
        // A clinical photo is clinical evidence: never dropped with rows in it.
        if (Schema::hasTable('trx_rme_clinical_photos')
            && DB::table('trx_rme_clinical_photos')->exists()) {
            throw new RuntimeException('trx_rme_clinical_photos holds clinical photos; refusing to drop it.');
        }

        Schema::dropIfExists('trx_rme_clinical_photos');
    }
};
