<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — tariff APPROVAL PROVENANCE.
|--------------------------------------------------------------------------
|
| THE DEFECT. Production TLK1 held 11 tariffs byte-identical to the Sprint 19
| TariffSeeder development defaults (one second, no audit row, no source) and
| `rme:tariff-authority-readiness` counted them exactly like an owner-approved
| price list. Two more tariffs would have made TLK1 TARIFF_READY on
| placeholder prices, and nothing machine-checked rule 192 §39's precondition
| "the owner's official tariff list imported".
|
| THE CONTRACT.
|   - A tariff carries APPROVAL PROVENANCE (a non-PHI owner-approval reference,
|     who recorded it, when) only when it arrived through the governed import.
|     Nothing is backfilled; a NULL reference reads "approval not recorded".
|   - Import --apply REQUIRES the reference, and the reviewed digest binds it.
|   - A tariff whose price already equals the approved list but has no
|     provenance is ATTESTED (provenance recorded, price untouched) — never
|     recreated, never overwritten. A different price is still a CONFLICT.
|   - A master-data change of price / effective date / treatment withdraws the
|     provenance: an approval covers the price it approved, not a later edit.
|   - Readiness reports provenance; a branch is strict-ELIGIBLE only when it is
|     TARIFF_READY and every tariff in force carries approval provenance.
*/

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\TariffImportService;
use App\Modules\Tariff\Services\TariffService;
use App\Modules\Tariff\Support\TariffAuditEvent;
use App\Modules\Tariff\Support\TariffProvenance;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rtaSetup());

const RTP_REF = 'OWNER-TARIFF-RT02-2026-10-17';

function rtpCsv(array $rows): string
{
    $path = tempArtifactFile('rtp-tariff-');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['treatment_code', 'price']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

/** @return array{0: int, 1: string} */
function rtpArtisan(string $command, array $parameters = []): array
{
    $exit = Artisan::call($command, $parameters);

    return [$exit, Artisan::output()];
}

/** @return array<string, mixed> */
function rtpBranchReport(string $code): array
{
    [, $out] = rtpArtisan('rme:tariff-authority-readiness', ['--json' => true]);

    return collect(json_decode($out, true)['branches'])->firstWhere('code', $code);
}

function rtpAdmin(): User
{
    return userInRole('Super Admin');
}

/** @param list<array{treatment_code: string, price: string}> $rows */
function rtpApply(string $branch, string $date, array $rows, string $reference = RTP_REF, ?User $actor = null): array
{
    $importer = app(TariffImportService::class);
    $digest = $importer->plan($branch, $date, $rows, $reference)['digest'];

    return $importer->apply($branch, $date, $rows, $digest, 'Daftar tarif resmi cabang dari owner.', $actor ?? rtpAdmin(), $reference);
}

// ─── Schema & mass assignment ───────────────────────────────────────────────

it('adds nullable approval provenance columns and backfills nothing', function () {
    expect(Schema::hasColumns('mst_tariffs', ['approval_reference', 'approval_recorded_by', 'approval_recorded_at']))->toBeTrue()
        ->and(Tariff::query()->whereNotNull('approval_reference')->count())->toBe(0)
        ->and(test()->scalingTariff->fresh()->hasApprovalProvenance())->toBeFalse();
});

it('never lets a request mass-assign approval provenance', function () {
    $tariff = Tariff::query()->create([
        'branch_id' => test()->otherBranch->id, 'treatment_id' => test()->scaling->id,
        'price' => '100000', 'effective_date' => '2026-10-01', 'is_active' => true,
        'approval_reference' => 'FORGED-REF-0001', 'approval_recorded_by' => rtpAdmin()->id, 'approval_recorded_at' => now(),
    ]);

    expect($tariff->fresh()->approval_reference)->toBeNull()
        ->and($tariff->fresh()->approval_recorded_by)->toBeNull();
});

it('creates a master-data tariff without provenance', function () {
    test()->actingAs(rtpAdmin());
    session(['active_branch_id' => test()->otherBranch->id]);
    $tariff = Tariff::query()->create([
        'branch_id' => test()->otherBranch->id, 'treatment_id' => test()->tambal->id,
        'price' => '90000', 'effective_date' => '2026-10-01', 'is_active' => true,
    ]);

    expect($tariff->fresh()->hasApprovalProvenance())->toBeFalse();
});

// ─── Approval reference gate ────────────────────────────────────────────────

it('plans without a reference but refuses to apply without one', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000']];
    $importer = app(TariffImportService::class);
    $plan = $importer->plan('RT02', '2026-10-01', $rows);

    expect($plan['refusals'])->toBe([])
        ->and($plan['appliable'])->toBeFalse()
        ->and($plan['approval_reference'])->toBeNull();

    expect(fn () => $importer->apply('RT02', '2026-10-01', $rows, $plan['digest'], 'Daftar tarif resmi owner.', rtpAdmin(), ''))
        ->toThrow(InvalidArgumentException::class, 'APPROVAL_REFERENCE_REQUIRED');

    expect(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0);
});

it('refuses a malformed approval reference', function (string $reference) {
    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [['treatment_code' => 'TRT-RTSC', 'price' => '175000']], $reference);

    expect(implode(' ', $plan['refusals']))->toContain('APPROVAL_REFERENCE_INVALID')
        ->and($plan['appliable'])->toBeFalse();
})->with([
    'too short' => ['AB12'],
    'contains spaces' => ['owner approved list'],
    'formula lead' => ['=HYPERLINK("x")'],
    'newline' => ["OWNER-REF\n2026"],
    'too long' => [str_repeat('A', 121)],
    'non-ascii' => ['OWNER-TÄRIF-2026'],
    // security review: a 13+ digit run is the shape of a KTP/NIK or phone number
    'nik-shaped' => ['7371012345678901'],
    'nik inside a token' => ['OWNER-7371012345678901'],
]);

it('binds the approval reference into the reviewed digest', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000']];
    $importer = app(TariffImportService::class);
    $digestA = $importer->plan('RT02', '2026-10-01', $rows, 'OWNER-REF-AAAA-01')['digest'];
    $digestB = $importer->plan('RT02', '2026-10-01', $rows, 'OWNER-REF-BBBB-02')['digest'];

    expect($digestA)->not->toBe($digestB);

    expect(fn () => $importer->apply('RT02', '2026-10-01', $rows, $digestA, 'Daftar tarif resmi owner.', rtpAdmin(), 'OWNER-REF-BBBB-02'))
        ->toThrow(InvalidArgumentException::class, 'PLAN_CHANGED');

    expect(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0);
});

// ─── Provenance recorded on apply ──────────────────────────────────────────

it('records approval provenance on every created tariff and in its audit row', function () {
    $actor = rtpAdmin();
    $result = rtpApply('RT02', '2026-10-01', [
        ['treatment_code' => 'TRT-RTSC', 'price' => '175000'],
        ['treatment_code' => 'TRT-RTTB', 'price' => '125000.50'],
    ], RTP_REF, $actor);

    $tariffs = Tariff::where('branch_id', test()->otherBranch->id)->get();
    $audit = AuditLog::where('action', TariffAuditEvent::IMPORTED)->get();

    expect($result['created'])->toBe(2)
        ->and($tariffs)->toHaveCount(2)
        ->and($tariffs->pluck('approval_reference')->unique()->all())->toBe([RTP_REF])
        ->and($tariffs->pluck('approval_recorded_by')->unique()->all())->toBe([(int) $actor->id])
        ->and($tariffs->every(fn (Tariff $t) => $t->approval_recorded_at !== null))->toBeTrue()
        ->and($audit)->toHaveCount(2)
        ->and($audit->first()->new_values['approval_reference'])->toBe(RTP_REF)
        ->and(json_encode($audit->first()->new_values))->not->toContain('Daftar tarif resmi');
});

// ─── ATTEST: identical price, no provenance ─────────────────────────────────

it('attests an identical unverified tariff without changing its price, id or date', function () {
    // RT01's tariffs mimic production TLK1: created directly, no provenance.
    $before = test()->scalingTariff->fresh();
    $count = Tariff::count();
    $importer = app(TariffImportService::class);
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '250000'], ['treatment_code' => 'TRT-RTTB', 'price' => '300000']];

    $plan = $importer->plan('RT01', '2026-01-01', $rows, RTP_REF);
    expect($plan['refusals'])->toBe([])
        ->and(collect($plan['rows'])->pluck('action')->unique()->all())->toBe([TariffImportService::ATTEST])
        ->and($plan['attests'])->toBe(2)
        ->and($plan['creates'])->toBe(0);

    $result = $importer->apply('RT01', '2026-01-01', $rows, $plan['digest'], 'Owner mengesahkan tarif yang ada.', rtpAdmin(), RTP_REF);
    $after = test()->scalingTariff->fresh();

    expect($result['attested'])->toBe(2)
        ->and($result['created'])->toBe(0)
        ->and(Tariff::count())->toBe($count)
        ->and($after->id)->toBe($before->id)
        ->and((string) $after->price)->toBe((string) $before->price)
        ->and($after->effective_date->toDateString())->toBe('2026-01-01')
        ->and($after->approval_reference)->toBe(RTP_REF)
        ->and(AuditLog::where('action', TariffAuditEvent::PROVENANCE_ATTESTED)->count())->toBe(2);

    $row = AuditLog::where('action', TariffAuditEvent::PROVENANCE_ATTESTED)->where('entity_id', $before->id)->sole();
    expect($row->old_values['approval_reference'])->toBeNull()
        ->and($row->new_values['approval_reference'])->toBe(RTP_REF)
        ->and($row->new_values['price_cents'])->toBe($row->old_values['price_cents']);
});

it('leaves an already-verified tariff UNCHANGED and never overwrites its reference', function () {
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']], 'OWNER-REF-FIRST-01');

    $plan = app(TariffImportService::class)->plan('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']], 'OWNER-REF-SECOND-2');
    expect($plan['rows'][0]['action'])->toBe(TariffImportService::UNCHANGED);

    $result = rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']], 'OWNER-REF-SECOND-2');
    expect($result['attested'])->toBe(0)
        ->and(test()->scalingTariff->fresh()->approval_reference)->toBe('OWNER-REF-FIRST-01');
});

it('still refuses a different price as a CONFLICT — provenance never launders an overwrite', function () {
    $plan = app(TariffImportService::class)->plan('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '260000']], RTP_REF);

    expect(implode(' ', $plan['refusals']))->toContain('CONFLICT')
        ->and((string) test()->scalingTariff->fresh()->price)->toBe('250000.00')
        ->and(test()->scalingTariff->fresh()->approval_reference)->toBeNull();
});

it('refuses an attest whose tariff moved after the dry run, recording nothing', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '250000']];
    $importer = app(TariffImportService::class);
    $digest = $importer->plan('RT01', '2026-01-01', $rows, RTP_REF)['digest'];

    test()->scalingTariff->forceFill(['price' => '255000'])->save();

    expect(fn () => $importer->apply('RT01', '2026-01-01', $rows, $digest, 'Owner mengesahkan tarif yang ada.', rtpAdmin(), RTP_REF))
        ->toThrow(InvalidArgumentException::class);

    expect(test()->scalingTariff->fresh()->approval_reference)->toBeNull()
        ->and(AuditLog::where('action', TariffAuditEvent::PROVENANCE_ATTESTED)->count())->toBe(0);
});

it('is idempotent: re-running an applied list writes nothing', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000'], ['treatment_code' => 'TRT-RTTB', 'price' => '125000']];
    rtpApply('RT02', '2026-10-01', $rows);
    $audits = AuditLog::count();

    $again = rtpApply('RT02', '2026-10-01', $rows);

    expect($again['created'])->toBe(0)
        ->and($again['attested'])->toBe(0)
        ->and($again['unchanged'])->toBe(2)
        ->and(AuditLog::count())->toBe($audits);
});

// ─── Master-data edits withdraw provenance ──────────────────────────────────

it('withdraws provenance when master data changes the price, and keeps it for a notes-only edit', function () {
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']]);
    test()->actingAs(rtpAdmin());
    $service = app(TariffService::class);

    $service->update(test()->scalingTariff->fresh(), [
        'treatment_id' => test()->scaling->id, 'price' => '250000', 'effective_date' => '2026-01-01',
        'is_active' => true, 'notes' => 'catatan internal',
    ]);
    expect(test()->scalingTariff->fresh()->approval_reference)->toBe(RTP_REF);

    $service->update(test()->scalingTariff->fresh(), [
        'treatment_id' => test()->scaling->id, 'price' => '275000', 'effective_date' => '2026-01-01', 'is_active' => true,
    ]);
    $fresh = test()->scalingTariff->fresh();
    $row = AuditLog::where('action', TariffAuditEvent::UPDATED)->latest('id')->first();

    expect($fresh->approval_reference)->toBeNull()
        ->and($fresh->approval_recorded_by)->toBeNull()
        ->and($fresh->approval_recorded_at)->toBeNull()
        ->and($row->old_values['approval_reference'])->toBe(RTP_REF)
        ->and($row->new_values['approval_reference'])->toBeNull();
});

it('withdraws provenance on ANY Eloquent price write, not only through TariffService (security review)', function () {
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']]);
    expect(test()->scalingTariff->fresh()->approval_reference)->toBe(RTP_REF);

    // The TariffSeeder shape: updateOrCreate keyed on (branch, treatment, date).
    Tariff::updateOrCreate(
        ['branch_id' => test()->branch->id, 'treatment_id' => test()->scaling->id, 'effective_date' => '2026-01-01'],
        ['price' => 50000, 'is_active' => true],
    );

    $fresh = test()->scalingTariff->fresh();
    expect((string) $fresh->price)->toBe('50000.00')
        ->and($fresh->approval_reference)->toBeNull()
        ->and($fresh->approval_recorded_by)->toBeNull();

    // A save that changes nothing an approval covers keeps it.
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTTB', 'price' => '300000']]);
    test()->tambalTariff->fresh()->forceFill(['notes' => 'catatan', 'is_active' => true])->save();
    expect(test()->tambalTariff->fresh()->approval_reference)->toBe(RTP_REF);
});

it('refuses an inactive actor at the service, not only at the command', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000']];
    $importer = app(TariffImportService::class);
    $digest = $importer->plan('RT02', '2026-10-01', $rows, RTP_REF)['digest'];
    $actor = rtpAdmin();
    $actor->forceFill(['is_active' => false])->save();

    expect(fn () => $importer->apply('RT02', '2026-10-01', $rows, $digest, 'Daftar tarif resmi cabang dari owner.', $actor->fresh(), RTP_REF))
        ->toThrow(InvalidArgumentException::class, 'ACTOR_NOT_AUTHORIZED');
    expect(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0);
});

it('withdraws provenance when master data moves the effective date', function () {
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']]);
    test()->actingAs(rtpAdmin());

    app(TariffService::class)->update(test()->scalingTariff->fresh(), [
        'treatment_id' => test()->scaling->id, 'price' => '250000', 'effective_date' => '2025-12-01', 'is_active' => true,
    ]);

    expect(test()->scalingTariff->fresh()->approval_reference)->toBeNull();
});

// ─── Readiness ──────────────────────────────────────────────────────────────

it('reports unverified provenance and never calls a branch strict-eligible on it', function () {
    $rt01 = rtpBranchReport('RT01');

    expect($rt01['provenance'])->toBe(TariffProvenance::UNVERIFIED)
        ->and($rt01['approved_tariffs'])->toBe(0)
        ->and($rt01['unverified_tariff_codes'])->toEqualCanonicalizing(['TRT-RTSC', 'TRT-RTTB'])
        ->and($rt01['strict_eligible'])->toBeFalse();

    [, $out] = rtpArtisan('rme:tariff-authority-readiness', ['--json' => true]);
    expect($out)->toContain('TARIFF_PROVENANCE_UNVERIFIED');

    expect(rtpBranchReport('RT02')['provenance'])->toBe(TariffProvenance::NONE);
});

it('makes a complete, fully verified branch strict-eligible — and an unapproved edit takes it away', function () {
    rtpApply('RT02', '2026-01-01', [
        ['treatment_code' => 'TRT-RTSC', 'price' => '175000'],
        ['treatment_code' => 'TRT-RTTB', 'price' => '125000'],
    ]);

    $rt02 = rtpBranchReport('RT02');
    $complete = $rt02['tariffed_treatments'] === $rt02['active_treatments'];

    expect($rt02['provenance'])->toBe(TariffProvenance::VERIFIED)
        ->and($rt02['approved_tariffs'])->toBe($rt02['tariffed_treatments'])
        ->and($rt02['strict_eligible'])->toBe($complete && $rt02['readiness'] === 'TARIFF_READY');

    test()->actingAs(rtpAdmin());
    $tariff = Tariff::where('branch_id', test()->otherBranch->id)->where('treatment_id', test()->scaling->id)->sole();
    app(TariffService::class)->update($tariff, [
        'treatment_id' => test()->scaling->id, 'price' => '180000', 'effective_date' => '2026-01-01', 'is_active' => true,
    ]);

    expect(rtpBranchReport('RT02')['provenance'])->toBe(TariffProvenance::UNVERIFIED)
        ->and(rtpBranchReport('RT02')['strict_eligible'])->toBeFalse();
});

it('judges the tariff IN FORCE: a newer unverified tariff hides an older verified one', function () {
    rtpApply('RT02', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '175000']]);
    rtaTariff(test()->otherBranch, test()->scaling, '190000', '2026-06-01');

    expect(rtpBranchReport('RT02')['unverified_tariff_codes'])->toContain('TRT-RTSC');
});

it('fails readiness for a strict branch whose tariffs lack approval provenance', function () {
    // Every active treatment of the suite DB gets an unverified tariff in RT01,
    // so RT01 is TARIFF_READY on coverage alone — exactly the TLK1 trap.
    foreach (Treatment::where('is_active', true)->get() as $treatment) {
        if (! Tariff::where('branch_id', test()->branch->id)->where('treatment_id', $treatment->id)->exists()) {
            rtaTariff(test()->branch, $treatment, '100000');
        }
    }
    rtaStrict(['RT01']);

    [$exit, $out] = rtpArtisan('rme:tariff-authority-readiness', ['--json' => true]);
    $rt01 = collect(json_decode($out, true)['branches'])->firstWhere('code', 'RT01');

    expect($rt01['readiness'])->toBe('TARIFF_READY')
        ->and($rt01['strict_eligible'])->toBeFalse()
        ->and($exit)->toBe(1)
        ->and($out)->toContain('STRICT_BRANCH_TARIFF_PROVENANCE_UNVERIFIED');
});

it('prints the provenance of the tariff in force in the template, price still blank', function () {
    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000']]);

    [$exit, $out] = rtpArtisan('rme:tariff-template', ['--branch' => 'RT01']);

    expect($exit)->toBe(0)
        ->and($out)->toContain('treatment_code,current_tariff_in_force,current_tariff_provenance,price')
        ->and($out)->toContain('TRT-RTSC,250000.00,VERIFIED,')
        ->and($out)->toContain('TRT-RTTB,300000.00,UNVERIFIED,');
});

// ─── Command end-to-end ─────────────────────────────────────────────────────

it('prints the reference and an appliable digest in the dry run, then applies it', function () {
    $file = rtpCsv([['TRT-RTSC', '175000'], ['TRT-RTTB', '125000']]);

    [$exit, $out] = rtpArtisan('rme:tariff-import', [
        'file' => $file, '--branch' => 'RT02', '--effective-date' => '2026-10-01', '--approval-reference' => RTP_REF,
    ]);
    preg_match('/PLAN_DIGEST=([0-9a-f]+)/', $out, $m);

    expect($exit)->toBe(0)
        ->and($out)->toContain('APPROVAL_REFERENCE='.RTP_REF)
        ->and($out)->toContain('CREATES=2 ATTESTS=0')
        ->and($m[1] ?? null)->not->toBeNull();

    [$exit, $out] = rtpArtisan('rme:tariff-import', [
        'file' => $file, '--branch' => 'RT02', '--effective-date' => '2026-10-01', '--approval-reference' => RTP_REF,
        '--apply' => true, '--confirm-plan' => $m[1], '--actor' => (string) rtpAdmin()->id, '--reason' => 'Daftar tarif resmi cabang dari owner.',
    ]);

    expect($exit)->toBe(0)->and($out)->toContain('APPLIED created=2 attested=0');
});

it('warns that a dry run without a reference cannot be applied, and the command refuses apply without one', function () {
    $file = rtpCsv([['TRT-RTSC', '175000']]);

    [$exit, $out] = rtpArtisan('rme:tariff-import', ['file' => $file, '--branch' => 'RT02', '--effective-date' => '2026-10-01']);
    expect($exit)->toBe(0)->and($out)->toContain('APPROVAL_REFERENCE=(none)');

    preg_match('/PLAN_DIGEST=([0-9a-f]+)/', $out, $m);
    [$exit, $out] = rtpArtisan('rme:tariff-import', [
        'file' => $file, '--branch' => 'RT02', '--effective-date' => '2026-10-01',
        '--apply' => true, '--confirm-plan' => $m[1], '--actor' => (string) rtpAdmin()->id, '--reason' => 'Daftar tarif resmi cabang dari owner.',
    ]);

    expect($exit)->toBe(1)->and($out)->toContain('APPROVAL_REFERENCE_REQUIRED')
        ->and(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0);
});

// ─── Financial integrity & posture ──────────────────────────────────────────

it('never touches invoices, invoice items or payments, and changes no pricing posture', function () {
    rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000)]);
    $fingerprint = fn () => [
        DB::table('trx_rme_invoices')->orderBy('id')->get(['id', 'subtotal', 'grand_total', 'status', 'updated_at'])->toJson(),
        DB::table('trx_rme_invoice_items')->orderBy('id')->get()->toJson(),
        DB::table('trx_rme_payments')->count(),
    ];
    $before = $fingerprint();
    $permissions = DB::table('role_has_permissions')->count();

    rtpApply('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '250000'], ['treatment_code' => 'TRT-RTTB', 'price' => '300000']]);
    rtpApply('RT02', '2026-10-01', [['treatment_code' => 'TRT-RTSC', 'price' => '175000']]);

    [, $out] = rtpArtisan('rme:tariff-authority-readiness', ['--json' => true]);
    $report = json_decode($out, true);

    expect($fingerprint())->toBe($before)
        ->and(RmeInvoice::count())->toBe(1)
        ->and(DB::table('role_has_permissions')->count())->toBe($permissions)
        ->and($report['strict_branch_codes'])->toBe([])
        ->and($report['price_exceptions_enabled'])->toBeFalse()
        ->and(app(RmeInvoiceCorrectionFeatureGuard::class)->enabled())->toBeFalse()
        ->and(collect($report['branches'])->pluck('mode')->unique()->all())->toBe(['TRANSITIONAL']);
});
