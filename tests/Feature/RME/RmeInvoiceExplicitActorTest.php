<?php

/*
| FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1 (rule 198) — the RME invoice
| service decides branch scope from the EXPLICIT actor the caller passes, never
| from the ambient session.
|
| Root cause it pins: RmeInvoiceService::cashierScopeBranchIds() asked
| RmeWorkingBranchScope::branchIdsFor(Auth::user()). That is the defect rule 197
| fixed for payments, left behind in the invoice READ lists (rule 197 §30):
|   1. it judged whoever happened to be logged in, not the cashier the caller
|      meant — the two differ for any non-HTTP caller;
|   2. with NO session, branchIdsFor(null) answers EVERY active RME branch, so
|      the cashier queue and the pending-billing list failed OPEN (reproduced on
|      the unmodified base: both branches' visits were returned).
| The service now resolves the explicit actor against the database (persisted,
| non-deleted, active, holding manage_rme_billing) and fails CLOSED otherwise:
| an empty scope for the read lists, a refusal for invoice creation.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../RmeInvoiceCorrection/helpers.php';

beforeEach(function () {
    ricSetup();

    $t = test();
    $t->elsewhere = Branch::factory()->create(['code' => 'IC09', 'name' => 'Cabang Lain Invoice', 'is_rme_enabled' => true, 'is_active' => true]);

    // A context-bound cashier working in the fixture branch, and one working in
    // another branch — the case the branch scope exists for.
    $t->branchCashier = User::factory()->create();
    rmeMakeKasirActive($t->branchCashier, $t->branch);
    $t->foreignCashier = User::factory()->create();
    rmeMakeKasirActive($t->foreignCashier, $t->elsewhere);

    // An ambient Super Admin session, to prove the service never borrows it.
    $t->ambientSuperAdmin = userInRole('Super Admin');

    $t->homeVisit = ricVisit();
    $t->foreignVisit = ricVisit($t->elsewhere);
});

/** @return list<int> */
function rieiPendingIds(User $actor, array $filters = []): array
{
    return collect(app(RmeInvoiceService::class)->paginatePendingVisits($actor, $filters)->items())
        ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

/** @return list<int> */
function rieiQueueIds(User $actor, array $filters = []): array
{
    return collect(app(RmeInvoiceService::class)->cashierHandoffQueue($actor, $filters)['groups'])
        ->flatten()->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
}

/** @return array<string, mixed> */
function rieiItems(): array
{
    return ['items' => [
        ['description' => 'Scaling gigi rahang atas', 'qty' => 1, 'unit_price' => 300000, 'discount' => 0, 'treatment_id' => test()->treatment->id],
    ]];
}

function rieiCreateRefused(callable $work, ClinicVisit $visit): void
{
    try {
        $work();
    } catch (AuthorizationException) {
        expect(RmeInvoice::query()->where('clinic_visit_id', $visit->id)->count())->toBe(0);

        return;
    }

    test()->fail('The invoice was created; the explicit cashier is not authorized for this visit.');
}

// ─── The fail-open the sprint closes: no ambient session ────────────────────

it('scopes the pending list to the explicit cashier when no session exists (was every branch)', function () {
    Auth::logout();
    expect(Auth::user())->toBeNull();

    expect(rieiPendingIds(test()->branchCashier))->toBe([(int) test()->homeVisit->id])
        ->and(rieiPendingIds(test()->foreignCashier))->toBe([(int) test()->foreignVisit->id]);
});

it('scopes the cashier handoff queue to the explicit cashier when no session exists (was every branch)', function () {
    Auth::logout();

    $home = rieiQueueIds(test()->branchCashier);
    $foreign = rieiQueueIds(test()->foreignCashier);

    expect($home)->toContain((int) test()->homeVisit->id)
        ->and($home)->not->toContain((int) test()->foreignVisit->id)
        ->and($foreign)->toContain((int) test()->foreignVisit->id)
        ->and($foreign)->not->toContain((int) test()->homeVisit->id);
});

// ─── Actor substitution, both directions ────────────────────────────────────

it('never lets an ambient Super Admin session widen a context-bound cashier', function () {
    test()->actingAs(test()->ambientSuperAdmin);

    expect(rieiPendingIds(test()->branchCashier))->toBe([(int) test()->homeVisit->id])
        ->and(rieiQueueIds(test()->branchCashier))->not->toContain((int) test()->foreignVisit->id);
});

it('never lets an ambient cashier session narrow or replace the explicit actor', function () {
    // The logged-in account works in the OTHER branch; the explicit actor is the
    // governance account. The explicit actor decides, so both branches appear.
    test()->actingAs(test()->foreignCashier);

    expect(rieiPendingIds(test()->ambientSuperAdmin))
        ->toBe(collect([test()->homeVisit->id, test()->foreignVisit->id])->map(fn ($id) => (int) $id)->sort()->values()->all());
    expect(rieiPendingIds(test()->branchCashier))->toBe([(int) test()->homeVisit->id]);
});

// ─── Unusable or unauthorized actors fail CLOSED (empty scope) ──────────────

it('gives an inactive actor an empty scope', function () {
    test()->branchCashier->forceFill(['is_active' => false])->save();
    Auth::logout();

    expect(rieiPendingIds(test()->branchCashier->fresh()))->toBe([])
        ->and(rieiQueueIds(test()->branchCashier->fresh()))->toBe([]);
});

it('gives a deleted actor an empty scope', function () {
    test()->branchCashier->delete();
    Auth::logout();

    expect(rieiPendingIds(test()->branchCashier))->toBe([])
        ->and(rieiQueueIds(test()->branchCashier))->toBe([]);
});

it('gives an unsaved actor object carrying a real governance id an empty scope', function () {
    $forged = new User(['name' => 'Bukan akun', 'email' => 'forged-invoice@example.test']);
    $forged->id = test()->ambientSuperAdmin->id;
    $forged->is_active = true;
    Auth::logout();

    expect(rieiPendingIds($forged))->toBe([])
        ->and(rieiQueueIds($forged))->toBe([]);
});

it('gives an unknown actor id an empty scope', function () {
    $ghost = new User(['name' => 'Akun hilang', 'email' => 'ghost-invoice@example.test']);
    $ghost->id = 9_999_999;
    $ghost->exists = true;
    Auth::logout();

    expect(rieiPendingIds($ghost))->toBe([])
        ->and(rieiQueueIds($ghost))->toBe([]);
});

it('judges a stale in-memory active flag against the database', function () {
    $stale = test()->branchCashier->fresh();
    User::query()->whereKey($stale->id)->update(['is_active' => false]);
    Auth::logout();

    // The object still says active; the persisted account does not.
    expect($stale->is_active)->toBeTrue()
        ->and(rieiPendingIds($stale))->toBe([]);
});

it('gives an active account without the billing permission an empty scope (was every branch)', function () {
    $noBilling = userWith(['view_clinic_visits']);
    Auth::logout();

    expect(rieiPendingIds($noBilling))->toBe([])
        ->and(rieiQueueIds($noBilling))->toBe([]);
});

it('keeps a context-bound cashier with no working branch at an empty scope', function () {
    $offShift = userInRole('Kasir');
    Auth::logout();

    expect(rieiPendingIds($offShift))->toBe([])
        ->and(rieiQueueIds($offShift))->toBe([]);
});

it('keeps the governance scope for an explicit Super Admin', function () {
    Auth::logout();

    expect(rieiPendingIds(test()->ambientSuperAdmin))
        ->toBe(collect([test()->homeVisit->id, test()->foreignVisit->id])->map(fn ($id) => (int) $id)->sort()->values()->all());
});

it('lets a request branch filter only narrow the explicit scope', function () {
    Auth::logout();

    expect(rieiQueueIds(test()->branchCashier, ['branch_id' => test()->elsewhere->id]))->toBe([]);
    expect(rieiQueueIds(test()->ambientSuperAdmin, ['branch_id' => test()->elsewhere->id]))
        ->toContain((int) test()->foreignVisit->id)
        ->not->toContain((int) test()->homeVisit->id);
});

// ─── The contract: an explicit, non-nullable actor on every entry point ─────

it('declares a non-nullable explicit actor on every actor-scoped entry point', function (string $method, string $name) {
    $parameter = collect((new ReflectionMethod(RmeInvoiceService::class, $method))->getParameters())
        ->first(fn (ReflectionParameter $p) => $p->getName() === $name);

    expect($parameter)->not->toBeNull()
        ->and($parameter->getType()?->allowsNull())->toBeFalse()
        ->and((string) $parameter->getType())->toBe(User::class)
        ->and($parameter->isOptional())->toBeFalse();
})->with([
    ['paginatePendingVisits', 'actor'],
    ['cashierHandoffQueue', 'actor'],
    ['findInvoiceForVisit', 'actor'],
    ['create', 'cashier'],
]);

it('rejects a null actor at the type boundary rather than falling back', function (string $method) {
    $service = app(RmeInvoiceService::class);

    expect(fn () => $service->{$method}(null, []))->toThrow(TypeError::class);
})->with(['paginatePendingVisits', 'cashierHandoffQueue']);

it('no longer exposes the ambient-branch invoice lookup', function () {
    // findInBranch() read BranchContext (the ambient session) and had no caller.
    expect(method_exists(RmeInvoiceService::class, 'findInBranch'))->toBeFalse();
});

it('reads no ambient session anywhere in the invoice service code', function () {
    // Comments are stripped with the tokenizer: the explanation of WHY the
    // ambient session is wrong names it, and that must not satisfy this check.
    $code = collect(token_get_all(file_get_contents(app_path('Modules/RmeInvoice/Services/RmeInvoiceService.php'))))
        ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token) => is_array($token) ? $token[1] : $token)
        ->implode('');

    foreach (['Auth::', 'auth()', "app('auth')", 'request()', 'Request::', 'session()', 'BranchContext'] as $ambient) {
        expect($code)->not->toContain($ambient);
    }
});

// ─── Invoice creation judges the explicit cashier ───────────────────────────

it('creates an invoice for an authorized explicit cashier with no session', function () {
    Auth::logout();

    $invoice = app(RmeInvoiceService::class)->create(test()->homeVisit, test()->branchCashier, rieiItems());

    expect((int) $invoice->cashier_id)->toBe((int) test()->branchCashier->id)
        ->and((int) $invoice->branch_id)->toBe((int) test()->branch->id);
});

it('refuses invoice creation for a wrong-branch cashier with no session', function () {
    Auth::logout();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, test()->foreignCashier, rieiItems()), test()->homeVisit);
});

it('never lets an ambient Super Admin session authorize a wrong-branch cashier to bill', function () {
    test()->actingAs(test()->ambientSuperAdmin);

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, test()->foreignCashier, rieiItems()), test()->homeVisit);
});

it('records the explicit cashier even when the ambient session belongs to another branch', function () {
    test()->actingAs(test()->foreignCashier);

    $invoice = app(RmeInvoiceService::class)->create(test()->homeVisit, test()->branchCashier, rieiItems());

    expect((int) $invoice->cashier_id)->toBe((int) test()->branchCashier->id);
});

it('refuses invoice creation for an inactive cashier', function () {
    test()->branchCashier->forceFill(['is_active' => false])->save();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, test()->branchCashier->fresh(), rieiItems()), test()->homeVisit);
});

it('refuses invoice creation for a deleted cashier', function () {
    test()->branchCashier->delete();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, test()->branchCashier, rieiItems()), test()->homeVisit);
});

it('refuses invoice creation for an unsaved cashier object carrying a governance id', function () {
    $forged = new User(['name' => 'Bukan akun', 'email' => 'forged-billing@example.test']);
    $forged->id = test()->ambientSuperAdmin->id;
    $forged->is_active = true;

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, $forged, rieiItems()), test()->homeVisit);
});

it('refuses invoice creation for an active account without the billing permission', function () {
    $noBilling = userWith(['view_clinic_visits']);
    Auth::logout();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, $noBilling, rieiItems()), test()->homeVisit);
});

it('writes no audit row and draws no invoice number for a refused actor', function () {
    $noBilling = userWith(['view_clinic_visits']);
    $auditBefore = AuditLog::query()->count();
    $invoicesBefore = RmeInvoice::query()->count();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->homeVisit, $noBilling, rieiItems()), test()->homeVisit);

    expect(AuditLog::query()->count())->toBe($auditBefore)
        ->and(RmeInvoice::query()->count())->toBe($invoicesBefore);
});

// ─── Invoice and payment judge the SAME persisted account (review F2) ───────

it('judges permission and scope on the persisted account in both invoice and payment', function () {
    // A long-lived caller holds an object whose roles were loaded while the
    // account was Super Admin; the role is then revoked and the account has no
    // billing permission. Gate::before reads the stale loaded relation, so the
    // object itself still "can" everything — the persisted account cannot.
    $stale = userInRole('Super Admin');
    $stale->load('roles');
    DB::table('model_has_roles')->where('model_id', $stale->id)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($stale->can('manage_rme_billing'))->toBeTrue();
    Auth::logout();

    $invoice = ricInvoice(test()->homeVisit);

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->foreignVisit, $stale, rieiItems()), test()->foreignVisit);
    expect(rieiPendingIds($stale))->toBe([]);

    try {
        app(RmePaymentService::class)->pay($invoice->fresh(), $stale, rmeFreshPaymentForm($invoice->fresh(), [
            'amount' => 100000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
        test()->fail('The payment was accepted for an account that no longer holds the billing permission.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('rme_invoice_id');
    }
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('scopes a demoted account by its persisted role, not by a stale loaded one', function () {
    // The object still carries a loaded Super Admin role (estate-wide); the
    // persisted account was demoted to a Kasir working in the fixture branch.
    // Every branch decision must follow the persisted account (mutation M10).
    $stale = userInRole('Super Admin');
    $stale->load('roles');
    DB::table('model_has_roles')->where('model_id', $stale->id)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    rmeMakeKasirActive(User::query()->findOrFail($stale->id), test()->branch);
    expect($stale->hasRole('Super Admin'))->toBeTrue();
    Auth::logout();

    rieiCreateRefused(fn () => app(RmeInvoiceService::class)->create(test()->foreignVisit, $stale, rieiItems()), test()->foreignVisit);
    expect(rieiPendingIds($stale))->toBe([(int) test()->homeVisit->id]);

    $foreignInvoice = app(RmeInvoiceService::class)->create(test()->foreignVisit, test()->ambientSuperAdmin, rieiItems());
    try {
        app(RmePaymentService::class)->pay($foreignInvoice->fresh(), $stale, rmeFreshPaymentForm($foreignInvoice->fresh(), [
            'amount' => 100000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
        test()->fail('The payment was accepted outside the demoted cashier\'s working branch.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('rme_invoice_id');
    }
    expect(RmePayment::query()->where('rme_invoice_id', $foreignInvoice->id)->count())->toBe(0);

    // Inside its own branch the persisted Kasir may bill, recorded as itself.
    $own = app(RmeInvoiceService::class)->create(test()->homeVisit, $stale, rieiItems());
    expect((int) $own->cashier_id)->toBe((int) $stale->id);
});

// ─── The existing-invoice lookup is actor-scoped too ────────────────────────

it('discloses an existing invoice only to an actor allowed in its branch', function () {
    $invoice = ricInvoice(test()->homeVisit);
    Auth::logout();

    $service = app(RmeInvoiceService::class);

    expect($service->findInvoiceForVisit(test()->homeVisit, test()->branchCashier)?->id)->toBe($invoice->id)
        ->and($service->findInvoiceForVisit(test()->homeVisit, test()->ambientSuperAdmin)?->id)->toBe($invoice->id)
        ->and($service->findInvoiceForVisit(test()->homeVisit, test()->foreignCashier))->toBeNull()
        ->and($service->findInvoiceForVisit(test()->homeVisit, userWith(['view_clinic_visits'])))->toBeNull();
});

// ─── HTTP: the controller passes its authenticated user, nothing else ───────

it('lists only the working branch on the cashier index for a context-bound cashier', function () {
    test()->actingAs(test()->branchCashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    test()->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertViewHas('visits', fn ($visits) => collect($visits->items())->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) test()->homeVisit->id]);
});

it('lists only the working branch on the handoff board, even with a crafted branch filter', function () {
    test()->actingAs(test()->branchCashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    test()->get(route('rme.cashier.handoff', ['branch_id' => test()->elsewhere->id]))
        ->assertOk()
        ->assertViewHas('groups', function ($groups) {
            $ids = collect($groups)->flatten()->pluck('id')->map(fn ($id) => (int) $id)->all();

            return ! in_array((int) test()->foreignVisit->id, $ids, true);
        });
});

it('ignores a request-supplied cashier id when billing over HTTP', function () {
    test()->actingAs(test()->branchCashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    test()->post(route('rme.cashier.store', test()->homeVisit), array_merge(rieiItems(), [
        'cashier_id' => test()->ambientSuperAdmin->id,
    ]))->assertRedirect();

    $invoice = RmeInvoice::query()->where('clinic_visit_id', test()->homeVisit->id)->sole();
    expect((int) $invoice->cashier_id)->toBe((int) test()->branchCashier->id);
});

it('refuses a wrong-branch cashier over HTTP without creating an invoice', function () {
    test()->actingAs(test()->foreignCashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    test()->post(route('rme.cashier.store', test()->homeVisit), rieiItems())->assertForbidden();

    expect(RmeInvoice::query()->where('clinic_visit_id', test()->homeVisit->id)->count())->toBe(0);
});

it('redirects an authorized cashier from the billing form to the existing invoice (no 500)', function () {
    $invoice = ricInvoice(test()->homeVisit);
    test()->actingAs(test()->branchCashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    test()->get(route('rme.cashier.create', test()->homeVisit))
        ->assertRedirect(route('rme.cashier.show', [test()->homeVisit, $invoice]));
});

it('keeps the cashier routes behind authentication', function () {
    Auth::logout();

    test()->get(route('rme.cashier.index'))->assertRedirect(route('login'));
    test()->get(route('rme.cashier.handoff'))->assertRedirect(route('login'));
    test()->post(route('rme.cashier.store', test()->homeVisit), rieiItems())->assertRedirect(route('login'));
    expect(RmeInvoice::query()->where('clinic_visit_id', test()->homeVisit->id)->count())->toBe(0);
});
