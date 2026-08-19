<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

it('computes adoption metrics from real records (eligible / with diagnosis / primary / rates)', function () {
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);
    ssDiagnosis($ctx, 'K02.1', 'primary');

    // Same-branch second visit WITHOUT structured diagnosis (legacy-style).
    $ctx2 = ssMakeVisit(['visit_date' => now()->toDateString(), 'branch_id' => $ctx['branch']->id]);
    $ctx2['mr']->update(['branch_id' => $ctx['branch']->id]);

    $metrics = app(SatusehatDiagnosisAdoptionService::class)->metrics(['branch_id' => $ctx['branch']->id]);

    expect($metrics['eligible_visits'])->toBe(2)
        ->and($metrics['with_structured_diagnosis'])->toBe(1)
        ->and($metrics['with_primary_diagnosis'])->toBe(1)
        ->and($metrics['missing_structured_diagnosis'])->toBe(1)
        ->and($metrics['adoption_rate'])->toBe(50.0)
        ->and($metrics['per_branch'][0]['eligible'])->toBe(2)
        ->and($metrics['per_doctor'])->not->toBeEmpty();

    Http::assertNothingSent();
});

it('drops a crafted branch filter outside the RME scope (IDOR-safe) and reports null rates on empty scope', function () {
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $foreign = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);

    $metrics = app(SatusehatDiagnosisAdoptionService::class)->metrics(['branch_id' => $foreign->id]);

    // The non-RME branch id is dropped — scope falls back to ALL RME branches.
    expect($metrics['scope_branch_ids'])->not->toContain($foreign->id)
        ->and($metrics['eligible_visits'])->toBeGreaterThanOrEqual(1);

    // Zero denominator yields null (N/A), never a fabricated 0%.
    $empty = app(SatusehatDiagnosisAdoptionService::class)->metrics([
        'branch_id' => $ctx['branch']->id,
        'from' => '2001-01-01',
        'to' => '2001-01-02',
    ]);
    expect($empty['eligible_visits'])->toBe(0)
        ->and($empty['adoption_rate'])->toBeNull();
});

it('counts overrides and rollout modes in the adoption metrics', function () {
    $configurer = userWith(['configure_diagnosis_rollout']);
    $doctor = userWith(['override_diagnosis_requirement']);
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);

    app(DiagnosisRolloutService::class)->setMode($ctx['branch'], 'pilot_enforced', 'pilot adopsi diagnosis cabang ini', $configurer);
    app(DiagnosisRolloutService::class)->grantOverride($ctx['mr'], 'Pasien emergensi, koding menyusul.', $doctor);

    $metrics = app(SatusehatDiagnosisAdoptionService::class)->metrics(['branch_id' => $ctx['branch']->id]);

    expect($metrics['override_count'])->toBe(1)
        ->and(collect($metrics['rollout_modes'])->firstWhere('branch_id', $ctx['branch']->id)['mode'])->toBe('pilot_enforced');
});

it('gates the adoption dashboard to Super Admin only (Owner and Kasir 403) and leaks no NIK', function () {
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);
    ssDiagnosis($ctx, 'K02.1', 'primary');

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group, so the
    // Owner — who still holds view_diagnosis_adoption — is denied as well.
    foreach (['Kasir', 'Owner'] as $role) {
        $this->actingAs(userInRole($role))->get(route('satusehat.adoption.index'))->assertForbidden();
    }

    // The route's own view_diagnosis_adoption requirement is unchanged.
    expect(userInRole('Owner')->can('view_diagnosis_adoption'))->toBeTrue()
        ->and(userInRole('Kasir')->can('view_diagnosis_adoption'))->toBeFalse()
        ->and(userInRole('Doctor')->can('view_diagnosis_adoption'))->toBeFalse();

    $response = $this->actingAs(superAdmin())->get(route('satusehat.adoption.index'));
    $response->assertOk();
    expect($response->getContent())->not->toContain($ctx['patient']->ktp_number);
});

it('adoption audit command scopes to a branch and emits JSON', function () {
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);
    ssDiagnosis($ctx, 'K02.1', 'primary');

    $this->artisan('satusehat:diagnosis-adoption-audit', ['--branch' => $ctx['branch']->id, '--json' => true])
        ->assertExitCode(0);

    Http::assertNothingSent();
});
