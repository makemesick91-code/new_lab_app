<?php

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

function s4bService(): ClinicalDiagnosisService
{
    return app(ClinicalDiagnosisService::class);
}

it('creates a new terminology entry as DRAFT and rejects an invalid ICD-10 code format', function () {
    $author = userWith(['manage_structured_diagnoses']);

    $draft = s4bService()->create([
        'code' => 'K02.3',
        'display' => 'Arrested dental caries',
        'source' => 'WHO ICD-10',
        'source_version' => 'ICD-10 2019',
    ], $author);

    expect($draft->status)->toBe(ClinicalDiagnosis::STATUS_DRAFT)
        ->and($draft->created_by)->toBe($author->id);

    // Malformed ICD-10 code refused (config-declared pattern, never guessed).
    expect(fn () => s4bService()->create(['code' => 'INVALID CODE !!', 'display' => 'x'], $author))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('walks the full lifecycle draft → under_review → approved → active and only then becomes selectable', function () {
    $author = userWith(['manage_structured_diagnoses']);
    $reviewer = userWith(['review_clinical_terminology', 'view_clinic_visits']);

    $dx = s4bService()->create(['code' => 'K02.4', 'display' => 'Odontoclasia', 'source' => 'WHO ICD-10'], $author);

    // Draft is never selectable in the doctor-facing search.
    $this->actingAs($reviewer);
    expect($this->getJson(route('rme.diagnoses.search', ['q' => 'Odontoclasia']))->json('data'))->toBe([]);

    // Invalid transition: activate straight from draft is refused.
    expect(fn () => s4bService()->activate($dx, $reviewer))->toThrow(ValidationException::class);

    s4bService()->submitForReview($dx, $author);
    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_UNDER_REVIEW);

    s4bService()->approve($dx->fresh(), $reviewer, 'Kode resmi WHO ICD-10, relevan untuk pilot dental.');
    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_APPROVED)
        ->and($dx->fresh()->approved_by)->toBe($reviewer->id);

    s4bService()->activate($dx->fresh(), $reviewer);
    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_ACTIVE);

    $codes = collect($this->getJson(route('rme.diagnoses.search', ['q' => 'Odontoclasia']))->json('data'))->pluck('code');
    expect($codes)->toContain('K02.4');
});

it('enforces separation of duties — the creator/submitter can never approve their own entry', function () {
    $author = userWith(['manage_structured_diagnoses', 'review_clinical_terminology']);

    $dx = s4bService()->create(['code' => 'K02.5', 'display' => 'Self approval test', 'source' => 'WHO ICD-10'], $author);
    s4bService()->submitForReview($dx, $author);

    expect(fn () => s4bService()->approve($dx->fresh(), $author, 'mencoba menyetujui sendiri'))
        ->toThrow(ValidationException::class);
});

it('refuses approval/activation of terminology without an official source', function () {
    $author = userWith(['manage_structured_diagnoses']);
    $reviewer = userWith(['review_clinical_terminology']);

    $dx = s4bService()->create(['code' => 'K02.8', 'display' => 'Other dental caries'], $author); // no source
    s4bService()->submitForReview($dx, $author);

    expect(fn () => s4bService()->approve($dx->fresh(), $reviewer, 'tanpa sumber resmi'))
        ->toThrow(ValidationException::class);
});

it('supports reject → resubmit and records the rejection reason', function () {
    $author = userWith(['manage_structured_diagnoses']);
    $reviewer = userWith(['review_clinical_terminology']);

    $dx = s4bService()->create(['code' => 'K03.0', 'display' => 'Excessive attrition of teeth', 'source' => 'WHO ICD-10'], $author);
    s4bService()->submitForReview($dx, $author);
    s4bService()->reject($dx->fresh(), $reviewer, 'Display tidak sesuai klasifikasi resmi.');

    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_REJECTED)
        ->and($dx->fresh()->rejected_reason)->toContain('klasifikasi resmi');

    s4bService()->submitForReview($dx->fresh(), $author);
    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_UNDER_REVIEW);
});

it('deprecates active terminology with an ACTIVE replacement only; history stays readable', function () {
    $reviewer = userWith(['review_clinical_terminology']);
    $ctx = ssMakeVisit();

    $old = ClinicalDiagnosis::factory()->create(['code' => 'K02.9', 'display' => 'Dental caries, unspecified']);
    $replacement = ClinicalDiagnosis::factory()->create(['code' => 'K02.1', 'display' => 'Caries of dentine']);
    $dxRow = ssDiagnosis($ctx, 'K02.9');

    // Replacement must be ACTIVE and different.
    expect(fn () => s4bService()->deprecate($old, $reviewer, $old->id))->toThrow(ValidationException::class);
    $draftReplacement = ClinicalDiagnosis::factory()->create(['code' => 'K02.2', 'status' => ClinicalDiagnosis::STATUS_DRAFT]);
    expect(fn () => s4bService()->deprecate($old, $reviewer, $draftReplacement->id))->toThrow(ValidationException::class);

    s4bService()->deprecate($old->fresh(), $reviewer, $replacement->id, 'Gunakan kode yang lebih spesifik.');

    $old = $old->fresh();
    expect($old->status)->toBe(ClinicalDiagnosis::STATUS_DEPRECATED)
        ->and($old->replacement_diagnosis_id)->toBe($replacement->id)
        ->and($old->deprecated_by)->toBe($reviewer->id);

    // Historical structured diagnosis row remains fully readable.
    expect($dxRow->fresh()->clinicalDiagnosis->code)->toBe('K02.9');

    // Deprecated code no longer selectable.
    $searcher = userWith(['view_clinic_visits']);
    $codes = collect($this->actingAs($searcher)->getJson(route('rme.diagnoses.search', ['q' => 'K02.9']))->json('data'))->pluck('code');
    expect($codes)->not->toContain('K02.9');
});

it('appends an audit row for every terminology lifecycle event', function () {
    $author = userWith(['manage_structured_diagnoses']);
    $reviewer = userWith(['review_clinical_terminology']);

    $dx = s4bService()->create(['code' => 'K03.1', 'display' => 'Abrasion of teeth', 'source' => 'WHO ICD-10'], $author);
    s4bService()->submitForReview($dx, $author);
    s4bService()->approve($dx->fresh(), $reviewer, 'Sesuai WHO ICD-10.');
    s4bService()->activate($dx->fresh(), $reviewer);
    s4bService()->deprecate($dx->fresh(), $reviewer);

    $events = SatusehatAuditLog::query()
        ->where('entity_type', 'clinical_diagnosis')
        ->where('entity_id', $dx->id)
        ->pluck('event');

    expect($events)->toContain(SatusehatAuditLog::EVENT_TERMINOLOGY_SUBMITTED)
        ->and($events)->toContain(SatusehatAuditLog::EVENT_TERMINOLOGY_APPROVED)
        ->and($events)->toContain(SatusehatAuditLog::EVENT_TERMINOLOGY_ACTIVATED)
        ->and($events)->toContain(SatusehatAuditLog::EVENT_TERMINOLOGY_DEPRECATED);
});

it('gates the review routes to Super Admin only while the manager-vs-reviewer permission split is kept', function () {
    $dx = ClinicalDiagnosis::factory()->create(['status' => ClinicalDiagnosis::STATUS_UNDER_REVIEW, 'submitted_by' => null, 'created_by' => null]);

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group, so the
    // dedicated reviewer is now denied over HTTP too — not only Kasir and the
    // master manager.
    $manager = userWith(['manage_structured_diagnoses']);
    $reviewer = userWith(['review_clinical_terminology']);

    foreach ([userInRole('Kasir'), $manager, $reviewer] as $user) {
        $this->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->post(route('satusehat.diagnoses.approve', $dx), ['reason' => 'peran ini tidak boleh menyetujui'])
            ->assertForbidden();
    }

    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_UNDER_REVIEW);

    // FIX-08: the route's own review_clinical_terminology requirement is
    // unchanged — the manager-vs-reviewer split is asserted on the permission
    // layer the `permission:` middleware itself evaluates.
    expect($manager->can('review_clinical_terminology'))->toBeFalse()
        ->and($reviewer->can('review_clinical_terminology'))->toBeTrue()
        ->and(userInRole('Kasir')->canAny(['manage_structured_diagnoses', 'review_clinical_terminology']))->toBeFalse();

    // The approval itself still works for the only actor that can reach it.
    $this->actingAs(superAdmin())
        ->post(route('satusehat.diagnoses.approve', $dx), ['reason' => 'Disetujui reviewer klinis.'])
        ->assertRedirect();

    expect($dx->fresh()->status)->toBe(ClinicalDiagnosis::STATUS_APPROVED);
});

it('Supervisor RME keeps its seeded lifecycle permissions but is denied every SATUSEHAT route now that the module is Super Admin only', function () {
    $supervisor = userInRole('Supervisor RME');

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group.
    $this->actingAs($supervisor)->get(route('satusehat.diagnoses.index'))->assertForbidden();
    $this->actingAs($supervisor)->get(route('satusehat.rollout.index'))->assertForbidden();
    $this->actingAs($supervisor)->get(route('satusehat.adoption.index'))->assertForbidden();

    // The seeded permissions themselves are untouched — this is a route-layer
    // restriction, not a permission revocation.
    expect($supervisor->can('manage_structured_diagnoses'))->toBeTrue()
        ->and($supervisor->can('review_clinical_terminology'))->toBeTrue()
        ->and($supervisor->can('configure_diagnosis_rollout'))->toBeTrue()
        ->and($supervisor->can('view_diagnosis_adoption'))->toBeTrue();

    // The surfaces themselves still render for the only role that may open them.
    $this->actingAs(superAdmin())->get(route('satusehat.diagnoses.index'))->assertOk();
    $this->actingAs(superAdmin())->get(route('satusehat.rollout.index'))->assertOk();
    $this->actingAs(superAdmin())->get(route('satusehat.adoption.index'))->assertOk();
});
