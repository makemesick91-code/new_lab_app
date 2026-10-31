<?php

declare(strict_types=1);

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — who may record a payout,
| through which surface, and what the absence of a designation does. Three layers are
| asserted separately: route middleware, the service (which every surface reaches),
| and the seeded role grants (which must give the capability to NOBODY).
*/

use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus as S;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rsSetup();
    rmeMakeKasirActive(test()->settler, test()->branch);
});

/** Every settlement route, with a parameter where it needs one. */
function rsAllRoutes(int $requestId, int $settlementId): array
{
    return [
        ['GET', route('rme.refund-settlements.index')],
        ['GET', route('rme.refund-settlements.show', $settlementId)],
        ['GET', route('rme.refund-settlements.evidence', $settlementId)],
        ['GET', route('rme.refund-settlements.create', $requestId)],
        ['POST', route('rme.refund-settlements.store', $requestId)],
        ['POST', route('rme.refund-settlements.confirm', $settlementId)],
        ['POST', route('rme.refund-settlements.outcome-unknown', $settlementId)],
        ['POST', route('rme.refund-settlements.abort', $settlementId)],
    ];
}

// ─── RS-AUTH: seeded grants and designation ──────────────────────────────────

it('RS-AUTH-01: no seeded role holds settle_rme_refund — not even Super Admin through its "*" grant', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(PermissionSeeder::PERMISSIONS)->toContain(RmeRefundSettlementService::PERMISSION_SETTLE)
        ->and(RoleSeeder::NO_ROLE_PERMISSIONS)->toBe([RmeRefundSettlementService::PERMISSION_SETTLE]);

    $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', RmeRefundSettlementService::PERMISSION_SETTLE))->pluck('name')->all();
    expect($holders)->toBe([]);

    foreach (['Front Office', 'Kasir', 'Admin Klinik', 'Kepala Cabang', 'Supervisor RME', 'Super Admin', 'Owner'] as $role) {
        expect(Role::findByName($role)->hasPermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE))->toBeFalse($role);
    }
});

it('RS-AUTH-02: the shipped settler list is empty, so a permission holder alone is refused (DENY_ALL)', function () {
    expect(require config_path('rme_refund_settlement.php'))->toMatchArray(['pilot' => ['branch_codes' => ['SPN4'], 'settlers' => []]]);

    $request = rsApproved();
    $holder = userInRole('Kasir');
    $holder->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);

    rsRefused(fn () => rsStart($request, $holder), R::SETTLER_NOT_AUTHORIZED);
    expect(RmeRefundSettlement::count())->toBe(0);
});

it('RS-AUTH-03: Super Admin cannot settle without a designation, and never a request it approved even when designated', function () {
    $request = rsApproved();
    rsRefused(fn () => rsStart($request, test()->superAdmin), R::SETTLER_NOT_AUTHORIZED);

    // Super Admin approves a second request, is then designated: still refused.
    [$invoice, $obligation] = rrrObligation('71');
    $second = rrrApprove(rrrSubmit($invoice, $obligation, [
        'owner_approval_reference' => rrrOwnerReference('71'),
        'patient_request_reference' => rrrPatientReference('71'),
    ]), test()->superAdmin);
    rsDesignate(test()->superAdmin);

    rsRefused(fn () => rsStart($second, test()->superAdmin), R::SETTLER_IS_APPROVER);
    expect(RmeRefundSettlement::count())->toBe(0);
});

it('RS-AUTH-04: Owner, Finance Klinik and Data Analyst are never settlers, even holding the permission and a designation', function (string $role) {
    $request = rsApproved();
    $reader = userInRole($role);
    $reader->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate($reader);

    rsRefused(fn () => rsStart($request, $reader), R::SETTLER_NOT_AUTHORIZED);
})->with(['Owner', 'Finance Klinik', 'Data Analyst']);

it('RS-AUTH-05: an inactive settler is refused', function () {
    $request = rsApproved();
    test()->settler->forceFill(['is_active' => false])->save();

    rsRefused(fn () => rsStart($request), R::ACTOR_UNUSABLE);
});

it('RS-AUTH-06: a designation revoked after start stops the confirmation (effective before the next action)', function () {
    $settlement = rsStart(rsApproved());
    rsSettlers([['user_id' => (int) test()->settler2->id, 'branch_codes' => ['IC01']]]);

    rsRefused(fn () => rsConfirm($settlement), R::SETTLER_NOT_AUTHORIZED);
    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
});

it('RS-AUTH-07: RF-10 B — the maker may settle when separately authorized; the approver never may', function () {
    $request = rsApproved();
    test()->frontOffice->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate(test()->frontOffice);

    $settlement = rsStart($request, test()->frontOffice);
    expect($settlement->status)->toBe(S::DISBURSING)
        ->and((int) $settlement->started_by)->toBe((int) test()->frontOffice->id)
        ->and((int) $settlement->approved_by)->not->toBe((int) test()->frontOffice->id);

    // The approver, separately designated, still cannot record the payout.
    test()->supervisor->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate(test()->supervisor);
    rsRefused(fn () => rsConfirm($settlement, test()->supervisor), R::SETTLER_IS_APPROVER);
});

it('RS-AUTH-08: a settler designated for another branch gets "not found", never a refusal that reveals the request', function () {
    $request = rsApproved();
    $other = rrrOtherBranch('IC02');
    rsPilot(['IC01', 'IC02']);
    $foreign = userInRole('Kasir');
    $foreign->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate($foreign, [$other->code]);

    rsNotFound(fn () => rsStart($request, $foreign));
    // Scope is decided before any input is read: a malformed payload from outside the
    // branch is still "not found", never a validation message that confirms the request.
    rsNotFound(fn () => rsStart($request, $foreign, 'CREDIT'));
    rsNotFound(fn () => rsStart($request, $foreign, overrides: ['amount' => '1.00']));
});

it('RS-AUTH-09: a malformed designation list voids every designation (fail closed)', function (array $entries) {
    $request = rsApproved();
    rsSettlers($entries);

    rsRefused(fn () => rsStart($request), R::SETTLER_NOT_AUTHORIZED);
})->with([
    'duplicate user' => [fn () => [['user_id' => (int) test()->settler->id, 'branch_codes' => ['IC01']], ['user_id' => (int) test()->settler->id, 'branch_codes' => ['IC01']]]],
    'unknown branch code' => [fn () => [['user_id' => (int) test()->settler->id, 'branch_codes' => ['NOPE9']]]],
    'MAIN' => [fn () => [['user_id' => (int) test()->settler->id, 'branch_codes' => ['MAIN']]]],
    'string user id' => [fn () => [['user_id' => (string) test()->settler->id, 'branch_codes' => ['IC01']]]],
]);

it('RS-AUTH-10: a branch outside the settlement pilot admits nobody', function () {
    $request = rsApproved();
    rsPilot([]);

    rsRefused(fn () => rsStart($request), R::SETTLER_NOT_AUTHORIZED);
});

// ─── RS-HTTP ─────────────────────────────────────────────────────────────────

it('RS-HTTP-01: with the flag OFF every settlement route answers 404, even for a designated settler', function () {
    $request = rsApproved();
    $settlement = rsStart($request);
    rsFlag(false);

    foreach (rsAllRoutes((int) $request->id, (int) $settlement->id) as [$method, $url]) {
        $response = $method === 'GET' ? $this->actingAs(test()->settler)->get($url) : $this->actingAs(test()->settler)->post($url, []);
        $response->assertNotFound();
    }

    rsRefused(fn () => rsConfirm($settlement), R::NOT_ENABLED);
});

it('RS-HTTP-02: the settlement capability also requires the refund request capability', function () {
    $request = rsApproved();
    rrrFlag(false);

    $this->actingAs(test()->settler)->get(route('rme.refund-settlements.index'))->assertNotFound();
    rsRefused(fn () => rsStart($request), R::NOT_ENABLED);
});

it('RS-HTTP-03: mutating routes require settle_rme_refund; reading accepts settle or approve', function () {
    $expected = [
        'rme.refund-settlements.index' => 'settle_rme_refund|approve_rme_refund',
        'rme.refund-settlements.show' => 'settle_rme_refund|approve_rme_refund',
        'rme.refund-settlements.evidence' => 'settle_rme_refund|approve_rme_refund',
        'rme.refund-settlements.create' => 'settle_rme_refund',
        'rme.refund-settlements.store' => 'settle_rme_refund',
        'rme.refund-settlements.confirm' => 'settle_rme_refund',
        'rme.refund-settlements.outcome-unknown' => 'settle_rme_refund',
        'rme.refund-settlements.abort' => 'settle_rme_refund',
    ];

    foreach ($expected as $name => $permission) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull($name)
            ->and($route->gatherMiddleware())->toContain('permission:'.$permission);
    }

    $reviewer = userInRole('Supervisor RME');
    $settlement = rsStart(rsApproved());
    foreach (['store' => [(int) $settlement->rme_refund_request_id], 'confirm' => [$settlement->id], 'outcome-unknown' => [$settlement->id], 'abort' => [$settlement->id]] as $action => $params) {
        $this->actingAs($reviewer)->post(route('rme.refund-settlements.'.$action, $params), [])->assertForbidden();
    }
});

it('RS-HTTP-04: the full payout through HTTP — start, confirm with evidence, read, download', function () {
    $request = rsApproved();

    $this->actingAs(test()->settler)->get(route('rme.refund-settlements.create', $request->id))->assertOk()
        ->assertSee('DISETUJUI ≠ DIBAYARKAN', false);

    $this->actingAs(test()->settler)->post(route('rme.refund-settlements.store', $request->id), rsStartData($request))
        ->assertRedirect();
    $settlement = RmeRefundSettlement::query()->sole();
    expect($settlement->status)->toBe(S::DISBURSING);

    $this->actingAs(test()->settler)->get(route('rme.refund-settlements.show', $settlement->id))->assertOk()
        ->assertSee('SEDANG DIBAYARKAN', false)
        ->assertSee('data-settlement-confirm', false);

    $this->actingAs(test()->settler)->post(route('rme.refund-settlements.confirm', $settlement->id), rsBankConfirmData() + ['evidence' => rsPdf()])
        ->assertRedirect(route('rme.refund-settlements.show', $settlement->id));

    $settlement->refresh();
    expect($settlement->status)->toBe(S::SETTLED)
        ->and($settlement->destination_account_last4)->toBe('4321');

    $page = $this->actingAs(test()->settler)->get(route('rme.refund-settlements.show', $settlement->id))->assertOk()
        ->assertSee('TELAH DIBAYARKAN', false)
        ->assertSee('data-settlement-evidence-link', false)
        ->assertDontSee('data-settlement-confirm', false);
    expect($page->getContent())->not->toContain($settlement->evidence_path);

    $download = $this->actingAs(test()->settler)->get(route('rme.refund-settlements.evidence', $settlement->id));
    $download->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect((string) $download->headers->get('Cache-Control'))->toContain('no-store');
});

it('RS-HTTP-05: the approver of the branch reads the payout and its proof; a foreign settler gets 404', function () {
    $settlement = rsConfirm(rsStart(rsApproved()));

    test()->kepalaCabang->refresh();
    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-settlements.show', $settlement->id))->assertOk()
        ->assertDontSee('data-settlement-confirm', false);
    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-settlements.evidence', $settlement->id))->assertOk();

    $other = rrrOtherBranch('IC02');
    rsPilot(['IC01', 'IC02']);
    $foreign = userInRole('Kasir');
    $foreign->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate($foreign, [$other->code]);
    rmeMakeKasirActive($foreign, $other);

    $this->actingAs($foreign)->get(route('rme.refund-settlements.show', $settlement->id))->assertNotFound();
    $this->actingAs($foreign)->get(route('rme.refund-settlements.evidence', $settlement->id))->assertNotFound();
    $this->actingAs($foreign)->post(route('rme.refund-settlements.confirm', $settlement->id), rsBankConfirmData())->assertNotFound();
});

it('RS-HTTP-06: a refusal returns a coded message and records nothing', function () {
    $request = rsApproved();

    $this->actingAs(test()->settler)
        ->from(route('rme.refund-settlements.create', $request->id))
        ->post(route('rme.refund-settlements.store', $request->id), rsStartData($request, overrides: ['amount' => '100000.00']))
        ->assertRedirect(route('rme.refund-settlements.create', $request->id))
        ->assertSessionHasErrors('amount');

    expect(RmeRefundSettlement::count())->toBe(0);
});

it('RS-HTTP-07: a confirmation without evidence or attestation is refused and leaves the payout open', function () {
    $settlement = rsStart(rsApproved());

    $this->actingAs(test()->settler)
        ->from(route('rme.refund-settlements.show', $settlement->id))
        ->post(route('rme.refund-settlements.confirm', $settlement->id), rsBankConfirmData())
        ->assertSessionHasErrors();

    $this->actingAs(test()->settler)
        ->from(route('rme.refund-settlements.show', $settlement->id))
        ->post(route('rme.refund-settlements.confirm', $settlement->id), array_merge(rsBankConfirmData(), ['attest_disbursed' => null, 'evidence' => rsPdf()]))
        ->assertSessionHasErrors();

    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
});

it('RS-HTTP-08: Owner and an oversight reader cannot reach the operational payout surface', function (string $role) {
    $settlement = rsConfirm(rsStart(rsApproved()));
    $reader = userInRole($role);

    $this->actingAs($reader)->get(route('rme.refund-settlements.index'))->assertForbidden();
    $this->actingAs($reader)->get(route('rme.refund-settlements.evidence', $settlement->id))->assertForbidden();
    $this->actingAs($reader)->post(route('rme.refund-settlements.abort', $settlement->id), [])->assertForbidden();
})->with(['Owner', 'Finance Klinik', 'Data Analyst']);

it('RS-HTTP-09: the refund request page shows the payout status beside the decision', function () {
    $request = rsApproved();
    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-requests.show', $request->id))->assertOk()
        ->assertSee('data-refund-settlement-panel', false)
        ->assertSee('data-refund-settlement-none', false);

    $settlement = rsStart($request);
    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-requests.show', $request->id))->assertOk()
        ->assertSee($settlement->settlement_number)
        ->assertSee(S::label(S::DISBURSING));
});

it('RS-HTTP-10: the sidebar entry appears only with the flag ON and the permission', function () {
    $link = 'data-sidebar-link="refund-settlements"';
    $this->actingAs(test()->settler)->get(route('rme.refund-settlements.index'))->assertOk()->assertSee($link, false);

    rsFlag(false);
    $this->actingAs(test()->settler)->get(route('dashboard'))->assertDontSee($link, false);
});

// ─── RS-OVS: oversight, read-only ────────────────────────────────────────────

it('RS-OVS-01: oversight readers read payouts GET-only, never the proof file; out of scope is 404', function (string $role) {
    $settlement = rsConfirm(rsStart(rsApproved()));
    $reader = userInRole($role);

    $this->actingAs($reader)->get(route('oversight.billing-corrections.refund-settlements.index'))->assertOk()
        ->assertSee('data-oversight-refund-settlement-summary', false);
    $page = $this->actingAs($reader)->get(route('oversight.billing-corrections.refund-settlements.show', $settlement->id))->assertOk()
        ->assertSee('TELAH DIBAYARKAN', false)
        ->assertDontSee('data-settlement-evidence-link', false)
        ->assertDontSee(route('rme.refund-settlements.confirm', $settlement->id), false)
        ->assertDontSee(route('rme.refund-settlements.abort', $settlement->id), false);
    expect($page->getContent())->not->toContain('/evidence')
        ->and($page->getContent())->not->toContain((string) $settlement->fresh()->evidence_path);

    $this->actingAs($reader)->get(route('oversight.billing-corrections.refund-settlements.show', 999999))->assertNotFound();
})->with(['Finance Klinik', 'Data Analyst', 'Owner']);

it('RS-OVS-02: every oversight settlement route is GET-only and gated by the oversight permission', function () {
    foreach (['index', 'show'] as $name) {
        $route = Route::getRoutes()->getByName('oversight.billing-corrections.refund-settlements.'.$name);
        expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([])
            ->and($route->gatherMiddleware())->toContain('permission:view_billing_correction_oversight');
    }
});

// ─── RS-SEC: structure ───────────────────────────────────────────────────────

it('RS-SEC-01: the settlement service reads no session, request, auth facade or BranchContext', function () {
    $source = (string) file_get_contents(app_path('Modules/RmeInvoice/Services/RmeRefundSettlementService.php'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    foreach (['Auth::', 'auth()', 'request()', 'session()', 'BranchContext'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }
});

it('RS-SEC-02: the settlement code never writes an incoming payment and offers no partial or credit method', function () {
    foreach (['Services/RmeRefundSettlementService.php', 'Repositories/RmeRefundSettlementRepository.php', 'Controllers/RmeRefundSettlementController.php'] as $file) {
        $code = (string) file_get_contents(app_path('Modules/RmeInvoice/'.$file));
        expect($code)->not->toMatch('/RmePayment::(create|query\(\)->(update|delete|insert))/')
            ->and($code)->not->toContain("table('trx_rme_payments')->update")
            ->and($code)->not->toContain("table('trx_rme_payments')->insert");
    }

    expect(RmeRefundSettlementMethod::ALL)->toBe(['BANK_TRANSFER', 'CASH']);
});

it('RS-SEC-03: the app-key registry lists the settlement intent as a current-key consumer', function () {
    $registry = json_encode(config('app_key_rotation'));
    expect($registry)->toContain('RmeRefundSettlementIntent.php');
});
