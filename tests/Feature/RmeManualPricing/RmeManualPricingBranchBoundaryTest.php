<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — the branch boundary of invoice
| creation, and a quantity the storage cannot hold.
|--------------------------------------------------------------------------
|
| Found by the independent security review of this revision (both pre-existing,
| fixed here because manual pricing raises their weight):
|
|   F1 (MEDIUM) — `RmeInvoicePolicy::create()` checked only `manage_rme_billing`,
|   while view / pay / viewReceipt already applied the working-branch scope. A
|   Kasir working at branch A could open the billing form of a branch-B visit and
|   ISSUE its invoice at prices they typed. With the tariff gone, the typed price
|   IS the authoritative financial snapshot, so the boundary now holds at the
|   policy (both controller actions) AND inside the service, on the locked visit.
|
|   F2 (LOW) — the quantity had no upper bound; a value beyond the integer column
|   reached the database and answered 500. It is now a coded 422. The bound is the
|   STORAGE limit, not a new business cap (none was decided).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoicePricingService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeTariffAuthority/helpers.php';

beforeEach(fn () => rtaSetup());

function rmpbKasirWorkingAt(Branch $branch): User
{
    $kasir = User::factory()->create();
    rmeMakeKasirActive($kasir, $branch);

    return $kasir;
}

// ─── F1 — the cashier bills only the branch they work in ───────────────────────

it('refuses a Kasir opening or submitting the billing of a visit outside the branch they work in, and writes nothing', function () {
    $kasir = rmpbKasirWorkingAt(test()->branch);
    $foreignVisit = rtaVisit(test()->otherBranch);

    $this->actingAs($kasir)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->get(route('rme.cashier.create', $foreignVisit))->assertForbidden();
    $this->post(route('rme.cashier.store', $foreignVisit), ['items' => [rtaLine(test()->scaling, '175000')]])
        ->assertForbidden();

    expect(RmeInvoice::count())->toBe(0);
});

it('refuses the same cross-branch invoice inside the service, for a caller that skips the policy', function () {
    $kasir = rmpbKasirWorkingAt(test()->branch);
    $foreignVisit = rtaVisit(test()->otherBranch);
    $this->actingAs($kasir);

    expect(fn () => app(RmeInvoiceService::class)->create($foreignVisit, $kasir, ['items' => [rtaLine(test()->scaling, '175000')]]))
        ->toThrow(AuthorizationException::class);

    expect(RmeInvoice::count())->toBe(0);
});

it('fails closed for a Kasir with no working branch at all', function () {
    $kasir = User::factory()->create();
    $kasir->assignRole('Kasir');
    $visit = rtaVisit();

    $this->actingAs($kasir)->withoutMiddleware(EnsureRmeOnlineContext::class);
    $this->get(route('rme.cashier.create', $visit))->assertForbidden();

    expect(fn () => app(RmeInvoiceService::class)->create($visit, $kasir, ['items' => [rtaLine(test()->scaling, '175000')]]))
        ->toThrow(AuthorizationException::class);
    expect(RmeInvoice::count())->toBe(0);
});

it('still lets a Kasir bill a visit of the branch they work in, at the price they typed', function () {
    $kasir = rmpbKasirWorkingAt(test()->branch);
    $visit = rtaVisit(test()->branch);

    $this->actingAs($kasir)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->get(route('rme.cashier.create', $visit))->assertOk();
    $this->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, '199000')]])
        ->assertSessionHasNoErrors()->assertRedirect();

    $invoice = RmeInvoice::query()->sole();
    expect((int) $invoice->branch_id)->toBe((int) test()->branch->id)
        ->and((float) $invoice->grand_total)->toEqual(199000.0);
});

it('keeps billing across branches for an actor whose scope is the whole RME estate', function () {
    // test()->cashier holds the permission without a context-bound role, so its
    // working scope is every active RME branch — the boundary narrows the
    // context-bound cashier, it never widens or removes anybody else.
    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->scaling, '150000')]);

    expect((int) $invoice->branch_id)->toBe((int) test()->otherBranch->id);
});

// ─── F2 — a quantity beyond the column is a 422, never a 500 ────────────────────

it('refuses a quantity the storage cannot hold with a 422 at the form, never a 500', function () {
    $visit = rtaVisit();
    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $line = rtaLine(test()->scaling, '0.01');
    $line['qty'] = (string) (RmeInvoicePricingService::MAX_QTY + 1);

    $this->from(route('rme.cashier.create', $visit))
        ->post(route('rme.cashier.store', $visit), ['items' => [$line]])
        ->assertSessionHasErrors('items.0.qty')
        ->assertRedirect(route('rme.cashier.create', $visit));

    expect(RmeInvoice::count())->toBe(0);
});

it('refuses the same quantity inside the service with a stable code, for a caller that skips the form', function () {
    $line = rtaLine(test()->scaling, '0.01');
    $line['qty'] = RmeInvoicePricingService::MAX_QTY + 1;

    try {
        rtaCreate(rtaVisit(), [$line]);
        test()->fail('The quantity was accepted.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['items'] ?? []))->toContain('INVOICE_QTY_OVERFLOW');
    }

    expect(RmeInvoice::count())->toBe(0);
});

it('accepts the largest quantity the storage holds', function () {
    $line = rtaLine(test()->scaling, '0.01');
    $line['qty'] = RmeInvoicePricingService::MAX_QTY;

    $item = rtaCreate(rtaVisit(), [$line])->items->sole();

    expect((int) $item->qty)->toBe(RmeInvoicePricingService::MAX_QTY)
        ->and((float) $item->subtotal)->toEqual(21_474_836.47);
});
