<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRequirementOverride;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
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

function s4bRollout(): DiagnosisRolloutService
{
    return app(DiagnosisRolloutService::class);
}

/** Draft MR with the mandatory handwriting so ONLY the diagnosis gate decides. */
function s4bFinalizableCtx(): array
{
    $ctx = ssMakeVisit();
    $ctx['mr']->update(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $ctx['mr']->id,
        'clinic_visit_id' => $ctx['visit']->id,
        'branch_id' => $ctx['branch']->id,
        'doctor_id' => $ctx['visit']->doctor_id,
    ]);

    return $ctx;
}

it('defaults to informational and refuses a blocking config default (no global enforcement)', function () {
    $ctx = ssMakeVisit();

    expect(s4bRollout()->modeForBranch($ctx['branch']->id))->toBe(DiagnosisRolloutSetting::MODE_INFORMATIONAL);

    // Even a misconfigured blocking default falls back to informational —
    // global hard enforcement is impossible by design.
    config()->set('clinical_diagnosis_rollout.default_mode', DiagnosisRolloutSetting::MODE_PILOT_ENFORCED);
    expect(s4bRollout()->defaultMode())->toBe(DiagnosisRolloutSetting::MODE_INFORMATIONAL)
        ->and(s4bRollout()->modeForBranch($ctx['branch']->id))->toBe(DiagnosisRolloutSetting::MODE_INFORMATIONAL);

    // A non-RME branch is always disabled.
    $nonRme = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    expect(s4bRollout()->modeForBranch($nonRme->id))->toBe(DiagnosisRolloutSetting::MODE_DISABLED);
});

it('configures a branch mode with a reason, audits the change, and refuses non-RME branches', function () {
    $ctx = ssMakeVisit();
    $actor = userWith(['configure_diagnosis_rollout']);

    $setting = s4bRollout()->setMode($ctx['branch'], DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'Pilot cabang disetujui owner untuk adopsi diagnosis.', $actor);

    expect($setting->mode)->toBe(DiagnosisRolloutSetting::MODE_PILOT_ENFORCED)
        ->and(s4bRollout()->modeForBranch($ctx['branch']->id))->toBe(DiagnosisRolloutSetting::MODE_PILOT_ENFORCED);

    $events = SatusehatAuditLog::query()->where('event', SatusehatAuditLog::EVENT_ROLLOUT_MODE_CHANGED)->count();
    expect($events)->toBe(1);

    // Reason too short refused.
    expect(fn () => s4bRollout()->setMode($ctx['branch'], DiagnosisRolloutSetting::MODE_WARNING, 'short', $actor))
        ->toThrow(ValidationException::class);

    // Non-RME branch refused.
    $nonRme = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    expect(fn () => s4bRollout()->setMode($nonRme, DiagnosisRolloutSetting::MODE_WARNING, 'alasan cukup panjang', $actor))
        ->toThrow(ValidationException::class);
});

it('pilot_enforced blocks finalization without an active primary; informational and warning never block', function () {
    $svc = app(MedicalRecordService::class);
    $actor = userWith(['configure_diagnosis_rollout']);

    // informational (default) — finalizes without any diagnosis.
    $ctx = s4bFinalizableCtx();
    $svc->finalize($ctx['mr']->fresh());
    expect($ctx['mr']->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);

    // warning — still finalizes.
    $ctx2 = s4bFinalizableCtx();
    s4bRollout()->setMode($ctx2['branch'], DiagnosisRolloutSetting::MODE_WARNING, 'fase warning cabang ini', $actor);
    $svc->finalize($ctx2['mr']->fresh());
    expect($ctx2['mr']->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);

    // pilot_enforced — blocked without a primary structured diagnosis.
    $ctx3 = s4bFinalizableCtx();
    s4bRollout()->setMode($ctx3['branch'], DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'pilot enforcement cabang ini', $actor);
    expect(fn () => $svc->finalize($ctx3['mr']->fresh()))->toThrow(ValidationException::class);
    expect($ctx3['mr']->fresh()->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('an ACTIVE primary satisfies the pilot gate; a deprecated-terminology primary does not', function () {
    $svc = app(MedicalRecordService::class);
    $actor = userWith(['configure_diagnosis_rollout']);
    $reviewer = userWith(['review_clinical_terminology']);

    $ctx = s4bFinalizableCtx();
    s4bRollout()->setMode($ctx['branch'], DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'pilot enforcement cabang ini', $actor);

    $dxRow = ssDiagnosis($ctx, 'K04.0', 'primary');

    // Active primary → allowed.
    expect(s4bRollout()->enforcementStateFor($ctx['mr']->fresh())['blocking'])->toBeFalse();

    // Terminology deprecated after selection → the primary no longer satisfies.
    app(ClinicalDiagnosisService::class)
        ->deprecate($dxRow->clinicalDiagnosis, $reviewer);

    expect(s4bRollout()->enforcementStateFor($ctx['mr']->fresh())['blocking'])->toBeTrue();
    expect(fn () => $svc->finalize($ctx['mr']->fresh()))->toThrow(ValidationException::class);
});

it('a reasoned emergency override unblocks finalization, is audited, and expires', function () {
    $svc = app(MedicalRecordService::class);
    $configurer = userWith(['configure_diagnosis_rollout']);
    $doctor = userWith(['override_diagnosis_requirement']);

    $ctx = s4bFinalizableCtx();
    s4bRollout()->setMode($ctx['branch'], DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'pilot enforcement cabang ini', $configurer);

    // Reason too short refused.
    expect(fn () => s4bRollout()->grantOverride($ctx['mr'], 'darurat', $doctor))->toThrow(ValidationException::class);

    $override = s4bRollout()->grantOverride($ctx['mr'], 'Pasien emergensi — koding menyusul setelah tindakan.', $doctor);
    expect($override->isUsable())->toBeTrue()
        ->and(SatusehatAuditLog::query()->where('event', SatusehatAuditLog::EVENT_DIAGNOSIS_OVERRIDE_GRANTED)->count())->toBe(1);

    // Finalization now passes without a diagnosis.
    $svc->finalize($ctx['mr']->fresh());
    expect($ctx['mr']->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);

    // An expired override no longer unblocks a (new) draft record.
    $override->update(['expires_at' => now()->subMinute()]);
    expect(s4bRollout()->enforcementStateFor($ctx['mr']->fresh())['blocking'])->toBeTrue();
});

it('override is only relevant on a pilot_enforced branch', function () {
    $doctor = userWith(['override_diagnosis_requirement']);
    $ctx = s4bFinalizableCtx(); // informational default

    expect(fn () => s4bRollout()->grantOverride($ctx['mr'], 'alasan yang cukup panjang untuk override', $doctor))
        ->toThrow(ValidationException::class);
});

it('gates the override route by permission and medical-record policy', function () {
    $configurer = userWith(['configure_diagnosis_rollout']);
    $ctx = s4bFinalizableCtx();
    s4bRollout()->setMode($ctx['branch'], DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'pilot enforcement cabang ini', $configurer);

    // Without the dedicated permission → 403 at the route middleware.
    $unauthorized = userWith(['manage_clinic_visits']);
    $this->actingAs($unauthorized)
        ->post(route('rme.visits.medical-record.diagnosis-override', [$ctx['visit'], $ctx['mr']]), [
            'reason' => 'alasan darurat yang cukup panjang',
        ])->assertForbidden();

    // Super Admin (Gate::before) records the override through the route.
    $this->actingAs(superAdmin())
        ->post(route('rme.visits.medical-record.diagnosis-override', [$ctx['visit'], $ctx['mr']]), [
            'reason' => 'alasan darurat yang cukup panjang',
        ])->assertRedirect();

    expect(DiagnosisRequirementOverride::query()->where('medical_record_id', $ctx['mr']->id)->count())->toBe(1);
});

it('gates rollout configuration routes to Super Admin only (Kasir AND Supervisor RME 403) and refuses request branch injection', function () {
    $ctx = ssMakeVisit();

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group, so the
    // Supervisor RME — who still holds configure_diagnosis_rollout — is denied
    // at the route layer too.
    foreach (['Kasir', 'Supervisor RME'] as $role) {
        $this->actingAs(userInRole($role))->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('satusehat.rollout.index'))->assertForbidden();
        $this->actingAs(userInRole($role))->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->post(route('satusehat.rollout.update', $ctx['branch']), ['mode' => 'warning', 'reason' => 'peran ini mencoba mengubah rollout'])
            ->assertForbidden();
    }

    // The route's own configure_diagnosis_rollout requirement is unchanged.
    expect(userInRole('Supervisor RME')->can('configure_diagnosis_rollout'))->toBeTrue()
        ->and(userInRole('Kasir')->can('configure_diagnosis_rollout'))->toBeFalse();

    // Nothing was written by the denied attempts.
    expect(s4bRollout()->modeForBranch($ctx['branch']->id))->not->toBe(DiagnosisRolloutSetting::MODE_WARNING);

    $this->actingAs(superAdmin())->get(route('satusehat.rollout.index'))->assertOk();
    $this->actingAs(superAdmin())
        ->post(route('satusehat.rollout.update', $ctx['branch']), ['mode' => 'warning', 'reason' => 'fase warning untuk adopsi'])
        ->assertRedirect();

    expect(s4bRollout()->modeForBranch($ctx['branch']->id))->toBe(DiagnosisRolloutSetting::MODE_WARNING);
});

it('rollout status + adoption audit + terminology audit commands run read-only without network', function () {
    $ctx = ssMakeVisit();
    ssDiagnosis($ctx, 'K02.1', 'primary');

    $this->artisan('satusehat:diagnosis-rollout-status', ['--json' => true])->assertExitCode(0);
    $this->artisan('satusehat:diagnosis-adoption-audit', ['--json' => true])->assertExitCode(0);
    $this->artisan('satusehat:terminology-audit', ['--json' => true])->assertExitCode(0);

    // Strict terminology audit flags an ACTIVE entry without official source.
    ClinicalDiagnosis::factory()->create(['code' => 'K09.0', 'source' => null]);
    $this->artisan('satusehat:terminology-audit', ['--strict' => true])->assertExitCode(2);

    Http::assertNothingSent();
});
