<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR1 — a FINALIZED odontogram is locked
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 (rule 240 §1): a finalized chart is no longer
| revisable; a correction goes through a supervised amendment (later PRs of the
| same release). This reverses Sprint 59 and the clause of rule 109 §4 / rule 134
| §5 that kept a finalized chart on the live encounter revisable.
|
| Three layers: the route (manage_clinic_visits + visit.room),
| OdontogramPolicy::update (scope first, then finalized ⇒ deny) and
| OdontogramService, which re-decides "finalized ⇒ refuse" on the chart row it
| locks after the visit row (rule 223 §6). The service binds Super Admin (CG-06).
| The authoring authority itself (rule 134; rule 241 §2 added the executed
| consent) is unchanged: a previous visit's chart is still refused by it first,
| with its own reason; the lock answers before a missing PTM (rule 240 §1.8,
| pinned in RmeOdontogramAuthorityMatrixTest).
|
| Selected by the existing `Odontogram` critical-gate token in both runner variants
| and declared in critical_gate_mandatory_suites (CG-19). Helper prefix `m33o` is
| unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->author = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
});

/** A chart on a live encounter with the EXECUTED consent every chart write needs (rule 241 §2). */
function m33oChart(Branch $branch, array $attributes = []): array
{
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $chart = Odontogram::factory()->create($attributes + [
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'summary_notes' => 'catatan awal',
        'tooth_map_payload' => ['teeth' => ['16' => ['status' => 'caries', 'conditions' => ['caries'], 'note' => 'awal']]],
    ]);

    return [$visit->fresh(), $chart->fresh()];
}

function m33oFinalized(Branch $branch, $finalizer): array
{
    return m33oChart($branch, [
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $finalizer->id,
    ]);
}

function m33oPayload(): array
{
    return [
        'summary_notes' => 'diubah setelah final',
        'tooth_map_payload' => ['teeth' => ['21' => ['status' => 'filling', 'conditions' => ['filling'], 'note' => 'baru']]],
    ];
}

/** True only when refused BY THE FINAL LOCK (key `odontogram`, the lock message). */
function m33oLocked(callable $write): bool
{
    try {
        $write();
    } catch (ValidationException $e) {
        return ($e->errors()['odontogram'][0] ?? null) === OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED;
    }

    return false;
}

/** @return array<string, mixed> */
function m33oSnapshot(Odontogram $chart): array
{
    $fresh = $chart->fresh();

    return [
        'status' => $fresh->status,
        'summary_notes' => $fresh->summary_notes,
        'additional_conditions' => $fresh->additional_conditions,
        'tooth_map_payload' => $fresh->tooth_map_payload,
        'finalized_at' => $fresh->finalized_at?->toIso8601String(),
        'finalized_by' => $fresh->finalized_by,
        'updated_by' => $fresh->updated_by,
    ];
}

// ─── Happy path ──────────────────────────────────────────────────────────────

it('keeps a DRAFT chart editable through the service and HTTP, then finalizes it', function () {
    [$visit, $chart] = m33oChart($this->branch);
    $this->actingAs($this->author);

    app(OdontogramService::class)->updatePlaceholder($chart, ['summary_notes' => 'draft diubah'], $this->author);
    $this->patch(route('rme.odontograms.update', $chart), [
        'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries']]]),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $final = app(OdontogramService::class)->finalize($chart->fresh(), $this->author);

    expect($final->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($final->finalized_by)->toBe($this->author->id)
        ->and($final->summary_notes)->toBe('draft diubah')
        ->and($final->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// ─── The chart: service and HTTP ─────────────────────────────────────────────

it('refuses updatePlaceholder and saveForVisit on a FINALIZED chart and writes nothing', function () {
    [$visit, $chart] = m33oFinalized($this->branch, $this->author);
    $before = m33oSnapshot($chart);
    $service = app(OdontogramService::class);

    expect(m33oLocked(fn () => $service->updatePlaceholder($chart, m33oPayload(), $this->author)))->toBeTrue()
        ->and(m33oLocked(fn () => $service->saveForVisit($visit, m33oPayload(), $this->author)))->toBeTrue()
        ->and(m33oSnapshot($chart))->toBe($before)
        ->and(Odontogram::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('refuses HTTP PATCH on a FINALIZED chart (update 403, visit save refused) and writes nothing', function () {
    [$visit, $chart] = m33oFinalized($this->branch, $this->author);
    $before = m33oSnapshot($chart);

    $this->actingAs($this->author)
        ->patch(route('rme.odontograms.update', $chart), [
            'summary_notes' => 'revisi catatan',
            'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries']]]),
        ])->assertForbidden();

    // The visit-scoped save route is authorized on the visit; the service refuses.
    $this->patch(route('rme.visits.odontogram.store', $visit), [
        'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries']]]),
    ])->assertSessionHasErrors(['odontogram' => OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED]);

    expect(m33oSnapshot($chart))->toBe($before);
});

it('decides the lock on the locked chart row, not on a stale draft copy', function () {
    [, $chart] = m33oChart($this->branch);
    $stale = $chart->fresh();
    app(OdontogramService::class)->finalize($chart, $this->author);
    $before = m33oSnapshot($chart);

    expect($stale->isFinalized())->toBeFalse()
        ->and(m33oLocked(fn () => app(OdontogramService::class)->updatePlaceholder($stale, m33oPayload(), $this->author)))->toBeTrue()
        ->and(m33oSnapshot($chart))->toBe($before);
});

it('never rewrites a finalized chart when it is finalized again, even from a stale draft copy', function () {
    [, $chart] = m33oChart($this->branch);
    $stale = $chart->fresh();
    app(OdontogramService::class)->finalize($chart, $this->author);
    $first = m33oSnapshot($chart);

    $this->travel(5)->minutes();
    $other = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
    $again = app(OdontogramService::class)->finalize($stale, $other);

    expect($again->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and(m33oSnapshot($chart))->toBe($first);
});

it('locks a chart that was already finalized before this rule by its status alone (no backfill)', function () {
    [, $chart] = m33oChart($this->branch, ['status' => Odontogram::STATUS_FINALIZED]);

    expect(m33oLocked(fn () => app(OdontogramService::class)->updatePlaceholder($chart, m33oPayload(), $this->author)))->toBeTrue()
        ->and($chart->fresh()->summary_notes)->toBe('catatan awal');
});

// ─── Super Admin ─────────────────────────────────────────────────────────────

it('refuses a Super Admin at the service layer on a FINALIZED chart (service and HTTP)', function () {
    [$visit, $chart] = m33oFinalized($this->branch, $this->author);
    $before = m33oSnapshot($chart);
    $admin = superAdmin();
    $this->actingAs($admin);

    expect(m33oLocked(fn () => app(OdontogramService::class)->updatePlaceholder($chart, m33oPayload(), $admin)))->toBeTrue()
        ->and(m33oLocked(fn () => app(OdontogramService::class)->saveForVisit($visit, m33oPayload(), $admin)))->toBeTrue();

    // Gate::before lets Super Admin past the policy; the service refusal stands.
    $this->patch(route('rme.odontograms.update', $chart), ['summary_notes' => 'admin'])
        ->assertRedirect()
        ->assertSessionHasErrors(['odontogram' => OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED]);

    expect(m33oSnapshot($chart))->toBe($before);
});

// ─── Authorization ───────────────────────────────────────────────────────────

it('denies update on a FINALIZED chart with the lock reason and keeps it readable', function () {
    [, $draft] = m33oChart($this->branch);
    [, $final] = m33oFinalized($this->branch, $this->author);

    $onDraft = Gate::forUser($this->author)->inspect('update', $draft);
    $onFinal = Gate::forUser($this->author)->inspect('update', $final);

    expect($onDraft->allowed())->toBeTrue()
        ->and($onFinal->denied())->toBeTrue()
        ->and($onFinal->message())->toBe(OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED)
        ->and(Gate::forUser($this->author)->allows('view', $final))->toBeTrue();
});

it('answers an out-of-scope account with the scope refusal, never the lock (scope first)', function () {
    [, $final] = m33oFinalized($this->branch, $this->author);

    $outsider = doctorWithOnlineContext($this->branch);
    $reader = rmeClinicalReader();

    foreach ([$outsider, $reader] as $user) {
        $answer = Gate::forUser($user)->inspect('update', $final);

        expect($answer->denied())->toBeTrue()
            ->and($answer->message())->not->toBe(OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED);
    }

    $this->actingAs($reader)
        ->patch(route('rme.odontograms.update', $final), ['summary_notes' => 'x'])
        ->assertForbidden();
    expect($final->fresh()->summary_notes)->toBe('catatan awal');
});

it('still refuses a previous visit\'s chart through the authoring authority first (rule 134, unchanged)', function () {
    [$visit, $old] = m33oFinalized($this->branch, $this->author);
    // A new encounter for the same patient makes the first chart history.
    $visit->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();
    $current = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $visit->patient_id]);
    rmeActiveConsentedEncounter($current);

    try {
        app(OdontogramService::class)->updatePlaceholder($old, m33oPayload(), $this->author);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toHaveKey('consent')
        ->and($old->fresh()->summary_notes)->toBe('catatan awal');
});

it('still refuses a chart outside the RME branch set before the lock is consulted', function () {
    $nonRme = Branch::factory()->create(['is_rme_enabled' => false]);
    $chart = Odontogram::factory()->create(['branch_id' => $nonRme->id, 'status' => Odontogram::STATUS_FINALIZED]);

    try {
        app(OdontogramService::class)->updatePlaceholder($chart, m33oPayload(), $this->author);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toHaveKey('odontogram_id');
});

// ─── The page mirrors the server (rule 135) ──────────────────────────────────

it('renders no submittable chart form on a FINALIZED chart, for a clinician or a Super Admin', function () {
    [$visit, $chart] = m33oFinalized($this->branch, $this->author);

    foreach ([$this->author, superAdmin()] as $user) {
        $this->actingAs($user)
            ->get(route('rme.visits.odontogram.show', $visit))
            ->assertOk()
            ->assertDontSee('action="'.route('rme.odontograms.update', $chart).'"', false)
            ->assertDontSee('action="'.route('rme.visits.odontogram.store', $visit).'"', false)
            ->assertDontSee('action="'.route('rme.odontograms.finalize', $chart).'"', false)
            ->assertSee(OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED)
            ->assertDontSee('masih dapat direvisi');
    }
});

it('still renders the chart form on a DRAFT chart (positive control)', function () {
    [$visit, $chart] = m33oChart($this->branch);

    $this->actingAs($this->author)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('action="'.route('rme.odontograms.update', $chart).'"', false)
        ->assertDontSee(OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED);
});

// ─── Lock order (rule 223 §6) ────────────────────────────────────────────────

it('locks the visit row before the chart row when deciding the lock (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    [, $chart] = m33oChart($this->branch);

    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = strtolower($query->sql);
        if (preg_match('/for (no key )?update/', $sql)) {
            $locks[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_odontograms') ? 'chart' : 'other');
        }
    });

    app(OdontogramService::class)->updatePlaceholder($chart, ['summary_notes' => 'urutan kunci'], $this->author);

    $visitAt = array_search('visit', $locks, true);
    $chartAt = array_search('chart', $locks, true);

    expect($visitAt)->not->toBeFalse()
        ->and($chartAt)->not->toBeFalse()
        ->and($visitAt)->toBeLessThan($chartAt);
});
