<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — readiness report, template and the
| controlled tariff import (owner decision D21 / D21h).
|--------------------------------------------------------------------------
|
| The import never decides a price. It refuses what cannot be authority, is a
| dry run unless --apply carries the digest of the reviewed plan, writes only
| CREATE rows inside one transaction, audits each, and is idempotent.
*/

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\TariffImportService;
use App\Modules\Tariff\Services\TariffService;
use App\Modules\Tariff\Support\TariffAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rtaSetup());

function rtaCsv(array $rows, array $header = ['treatment_code', 'price']): string
{
    // Canonical owned allocation: drained by the global afterEach (rule 123).
    $path = tempArtifactFile('rta-tariff-');
    $handle = fopen($path, 'w');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

/** @return array{0: int, 1: string} */
function rtaArtisan(string $command, array $parameters = []): array
{
    $exit = Artisan::call($command, $parameters);

    return [$exit, Artisan::output()];
}

// ─── Readiness ─────────────────────────────────────────────────────────────

// REVISED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1: this used to assert every
// branch reported mode TRANSITIONAL (a BRANCH_TRANSITIONAL WATCH). Manual pricing
// is the policy, so every branch reports MANUAL, no BRANCH_TRANSITIONAL finding
// exists, and the report names the pricing authority. Coverage and the
// unverified-tariff WATCH (tariff-master hygiene, rule 193) are unchanged.
it('reports every RME branch MANUAL with its tariff coverage and missing tariffs, read-only', function () {
    $tariffs = Tariff::count();
    [$exit, $out] = rtaArtisan('rme:tariff-authority-readiness', ['--json' => true]);
    $report = json_decode($out, true);
    $rt01 = collect($report['branches'])->firstWhere('code', 'RT01');
    $rt02 = collect($report['branches'])->firstWhere('code', 'RT02');

    expect($exit)->toBe(0)
        ->and($report['decision'])->toBe('WATCH')
        ->and($report['price_exceptions_enabled'])->toBeFalse()
        ->and($report['pricing_authority'])->toBe('MANUAL_CASHIER_ENTRY')
        ->and(collect($report['findings'])->pluck('code')->all())->not->toContain('BRANCH_TRANSITIONAL')
        ->and(collect($report['findings'])->pluck('code')->all())->toContain('TARIFF_PROVENANCE_UNVERIFIED')
        ->and($report['notes']['tariff_never_blocks_billing'])->toBeTrue()
        ->and($rt01['mode'])->toBe('MANUAL')
        ->and($rt02['mode'])->toBe('MANUAL')
        ->and($rt01['tariffed_treatments'])->toBe(2)
        ->and($rt02['readiness'])->toBe('NO_TARIFFS')
        ->and($rt02['missing_tariff_codes'])->toContain('TRT-RTSC')
        ->and(Tariff::count())->toBe($tariffs);
});

it('fails (exit 1) when a strict branch is not tariff-ready, and WATCH fails only with --strict', function () {
    expect(rtaArtisan('rme:tariff-authority-readiness', ['--strict' => true])[0])->toBe(1);

    rtaStrict(['RT02']);
    [$exit, $out] = rtaArtisan('rme:tariff-authority-readiness', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_encode(json_decode($out, true)['findings']))->toContain('STRICT_BRANCH_NOT_READY');
});

it('fails on a strict code that names no active RME branch', function () {
    rtaStrict(['ZZ99']);
    [$exit, $out] = rtaArtisan('rme:tariff-authority-readiness', ['--json' => true]);

    expect($exit)->toBe(1)->and($out)->toContain('STRICT_CODE_NOT_AN_RME_BRANCH');
});

// REVISED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1: the same two lines used to
// count as 1 tariff-verified + 1 transitional. They are both manual entries now;
// historical tariff-era and transitional rows are still counted in their own
// buckets, and no history is invented.
it('counts billed lines by provenance without inventing history', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000), rtaLine(test()->tambal, 1)]);
    RmeInvoiceItem::query()->create([
        'rme_invoice_id' => $invoice->id, 'description' => 'Baris era tarif', 'qty' => 1,
        'unit_price' => 1000, 'discount' => 0, 'subtotal' => 1000,
        'price_source' => 'TRANSITIONAL_NO_TARIFF',
    ]);
    [, $out] = rtaArtisan('rme:tariff-authority-readiness', ['--json' => true]);
    $rt01 = collect(json_decode($out, true)['branches'])->firstWhere('code', 'RT01');

    expect($rt01['billed_lines'])->toBe(3)
        ->and($rt01['billed_lines_manual'])->toBe(2)
        ->and($rt01['billed_lines_tariff_verified'])->toBe(0)
        ->and($rt01['billed_lines_transitional'])->toBe(1)
        ->and($rt01['billed_lines_not_recorded'])->toBe(0);
});

// ─── Template ──────────────────────────────────────────────────────────────

it('prints an import-ready template with the price column blank and the tariff in force for reference', function () {
    [$exit, $out] = rtaArtisan('rme:tariff-template', ['--branch' => 'RT01']);

    expect($exit)->toBe(0)
        // FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 added current_tariff_provenance.
        ->and($out)->toContain('treatment_code,current_tariff_in_force,current_tariff_provenance,price')
        ->and($out)->toContain('TRT-RTSC,250000.00,UNVERIFIED,')
        ->and(rtaArtisan('rme:tariff-template', ['--branch' => 'NOPE'])[0])->toBe(1);
});

// ─── Import ────────────────────────────────────────────────────────────────

it('dry-runs by default and writes nothing', function () {
    $file = rtaCsv([['TRT-RTSC', '175000'], ['TRT-RTTB', '125000.50']]);
    $before = Tariff::count();

    [$exit, $out] = rtaArtisan('rme:tariff-import', ['file' => $file, '--branch' => 'RT02', '--effective-date' => '2026-10-01']);

    expect($exit)->toBe(0)
        ->and($out)->toContain('CREATES=2')
        ->and($out)->toContain('DRY RUN')
        ->and(Tariff::count())->toBe($before);
});

it('applies the reviewed plan once, audits every tariff, and is idempotent on re-run', function () {
    // FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1: --apply carries the owner-approval
    // reference, and the reviewed digest binds it.
    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [
        ['treatment_code' => 'TRT-RTSC', 'price' => '175000'],
        ['treatment_code' => 'TRT-RTTB', 'price' => '125000.50'],
    ], 'OWNER-TARIFF-RT02-01');
    $actor = userInRole('Super Admin');

    [$exit, $out] = rtaArtisan('rme:tariff-import', [
        'file' => rtaCsv([['TRT-RTSC', '175000'], ['TRT-RTTB', '125000.50']]),
        '--branch' => 'RT02', '--effective-date' => '2026-10-01',
        '--apply' => true, '--confirm-plan' => $plan['digest'], '--actor' => (string) $actor->id,
        '--reason' => 'Daftar tarif resmi cabang dari owner.', '--approval-reference' => 'OWNER-TARIFF-RT02-01',
    ]);

    expect($exit)->toBe(0)->and($out)->toContain('APPLIED created=2')
        ->and(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(2)
        ->and(AuditLog::where('action', TariffAuditEvent::IMPORTED)->count())->toBe(2)
        ->and((string) Tariff::where('branch_id', test()->otherBranch->id)->where('treatment_id', test()->tambal->id)->value('price'))->toBe('125000.50');

    // Re-run: everything UNCHANGED, nothing written.
    $again = app(TariffImportService::class)->plan('RT02', '2026-10-01', [['treatment_code' => 'TRT-RTSC', 'price' => '175000']], 'OWNER-TARIFF-RT02-01');
    expect($again['creates'])->toBe(0)->and($again['rows'][0]['action'])->toBe(TariffImportService::UNCHANGED);
});

it('refuses apply without the reviewed digest, a reason, or an authorized actor', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000']];
    $importer = app(TariffImportService::class);
    $ref = 'OWNER-TARIFF-RT02-01';
    $digest = $importer->plan('RT02', '2026-10-01', $rows, $ref)['digest'];

    expect(fn () => $importer->apply('RT02', '2026-10-01', $rows, 'deadbeef', 'Daftar tarif resmi owner.', userInRole('Super Admin'), $ref))->toThrow(InvalidArgumentException::class, 'PLAN_CHANGED')
        ->and(fn () => $importer->apply('RT02', '2026-10-01', $rows, $digest, 'singkat', userInRole('Super Admin'), $ref))->toThrow(InvalidArgumentException::class, 'REASON_INVALID')
        ->and(fn () => $importer->apply('RT02', '2026-10-01', $rows, $digest, 'Daftar tarif resmi owner.', userWith(['manage_rme_billing']), $ref))->toThrow(InvalidArgumentException::class, 'ACTOR_NOT_AUTHORIZED');

    expect(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0);
});

it('refuses the whole plan on any row that cannot be price authority — no partial import', function (array $row, string $code) {
    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [
        ['treatment_code' => 'TRT-RTTB', 'price' => '100000'],
        $row,
    ]);

    expect($plan['refusals'])->not->toBeEmpty()
        ->and(implode(' ', $plan['refusals']))->toContain($code);
})->with([
    'unknown treatment' => [['treatment_code' => 'TRT-NOPE', 'price' => '100000'], 'TREATMENT_UNKNOWN'],
    'zero price' => [['treatment_code' => 'TRT-RTSC', 'price' => '0'], 'PRICE_ZERO'],
    'negative price' => [['treatment_code' => 'TRT-RTSC', 'price' => '-5'], 'PRICE_INVALID'],
    'three decimals' => [['treatment_code' => 'TRT-RTSC', 'price' => '1.234'], 'PRICE_INVALID'],
    'formula text' => [['treatment_code' => 'TRT-RTSC', 'price' => '=1+1'], 'PRICE_INVALID'],
    'duplicate code' => [['treatment_code' => 'TRT-RTTB', 'price' => '100000'], 'DUPLICATE'],
]);

it('refuses a conflict with an existing tariff at the same effective date instead of overwriting it', function () {
    $plan = app(TariffImportService::class)->plan('RT01', '2026-01-01', [['treatment_code' => 'TRT-RTSC', 'price' => '999999']]);

    expect(implode(' ', $plan['refusals']))->toContain('CONFLICT')
        ->and((string) test()->scalingTariff->fresh()->price)->toBe('250000.00');
});

it('refuses an unknown, non-RME branch or an impossible date', function () {
    $importer = app(TariffImportService::class);
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '1000']];

    expect(implode(' ', $importer->plan('ZZ99', '2026-10-01', $rows)['refusals']))->toContain('BRANCH_UNKNOWN')
        ->and(implode(' ', $importer->plan('RT02', '2026-02-30', $rows)['refusals']))->toContain('EFFECTIVE_DATE_INVALID');

    test()->otherBranch->forceFill(['is_rme_enabled' => false])->save();
    expect(implode(' ', $importer->plan('RT02', '2026-10-01', $rows)['refusals']))->toContain('BRANCH_NOT_RME');
});

it('rolls the whole import back when a write fails', function () {
    $rows = [['treatment_code' => 'TRT-RTSC', 'price' => '175000'], ['treatment_code' => 'TRT-RTTB', 'price' => '125000']];
    $importer = app(TariffImportService::class);
    $digest = $importer->plan('RT02', '2026-10-01', $rows, 'OWNER-TARIFF-RT02-01')['digest'];

    Tariff::creating(function (Tariff $tariff) {
        if ((int) $tariff->treatment_id === (int) test()->tambal->id) {
            throw new RuntimeException('simulated failure');
        }
    });

    expect(fn () => $importer->apply('RT02', '2026-10-01', $rows, $digest, 'Daftar tarif resmi owner.', userInRole('Super Admin'), 'OWNER-TARIFF-RT02-01'))->toThrow(RuntimeException::class);
    expect(Tariff::where('branch_id', test()->otherBranch->id)->count())->toBe(0)
        ->and(AuditLog::where('action', TariffAuditEvent::IMPORTED)->count())->toBe(0);

    Tariff::flushEventListeners();
});

it('audits a tariff change made through master data', function () {
    $this->actingAs(userInRole('Super Admin'));
    app(TariffService::class)->update(test()->scalingTariff, ['price' => '260000']);

    $row = AuditLog::where('action', TariffAuditEvent::UPDATED)->sole();
    expect($row->old_values['price_cents'])->toBe(25_000_000)
        ->and($row->new_values['price_cents'])->toBe(26_000_000)
        ->and($row->new_values)->not->toHaveKey('notes');
});

// ─── Security review findings (this sprint) ────────────────────────────────

it('matches a treatment code case-insensitively, so the template output imports back', function () {
    test()->tambal->forceFill(['code' => 'trt-rttb-lower'])->save();

    [, $out] = rtaArtisan('rme:tariff-template', ['--branch' => 'RT02']);
    expect($out)->toContain('trt-rttb-lower,');

    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [['treatment_code' => 'trt-rttb-lower', 'price' => '100000']]);
    expect($plan['refusals'])->toBe([])->and($plan['creates'])->toBe(1);
});

it('refuses a code that names two treatments differing only by letter case, instead of guessing', function () {
    Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'code' => 'trt-rtsc']);

    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [['treatment_code' => 'TRT-RTSC', 'price' => '100000']]);
    expect(implode(' ', $plan['refusals']))->toContain('CODE_AMBIGUOUS');
});

it('neutralises a formula-leading treatment code in the template and round-trips it on import', function () {
    test()->tambal->forceFill(['code' => '=HYPERLINK("x")'])->save();

    [, $out] = rtaArtisan('rme:tariff-template', ['--branch' => 'RT02']);
    expect($out)->toContain("'=HYPERLINK")
        ->and($out)->not->toMatch('/^=HYPERLINK/m');

    $plan = app(TariffImportService::class)->plan('RT02', '2026-10-01', [['treatment_code' => "'=HYPERLINK(\"x\")", 'price' => '100000']]);
    expect($plan['refusals'])->toBe([])->and($plan['creates'])->toBe(1);
});
