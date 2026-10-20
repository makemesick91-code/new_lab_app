<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — invoice correction under owner
| decisions D21(4) and D1.
|--------------------------------------------------------------------------
|
| D21(4) — SUPERSEDED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21
| revised 2026-10-20, manual pricing). It used to require every resulting line to
| equal its branch tariff, with no discount, and to make a correction STALE
| (TARIFF_AUTHORITY_CHANGED) when a tariff moved. Each of those tests is revised
| in place below and now pins the manual contract: the corrected price is a
| server-validated MANUAL price, independent of any tariff; a tariff change never
| makes a correction stale; applied lines carry CORRECTION_MANUAL_ENTRY.
|
| D1: Kasir submits, Admin Klinik approves within its branch, Super Admin
| approves across branches, and nobody approves their own request. The
| enforcement already exists; this suite pins it as the owner's contract. The
| role GRANTS are an activation step and are NOT made by this sprint.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\Tariff\Models\Tariff;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

function rctExpectRefused(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->toContain($code);

        return;
    }

    test()->fail('Expected a refusal with '.$code.'.');
}

// ─── D21(4) — submission ───────────────────────────────────────────────────

it('accepts a correction whose every resulting line is at its branch tariff', function () {
    $correction = ricSubmit(ricInvoice()); // Tambal 200.000 → its tariff 50.000; Scaling kept at its tariff

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $correction->proposed_grand_total)->toEqual(350000.0);
});

// REVISED (was: refused CORRECTION_TARIFF_PRICE_MISMATCH).
it('accepts a correction that moves a line to a price no tariff carries', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice, lines: ricLinesReducing($invoice, '45000'));

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $correction->proposed_grand_total)->toEqual(345000.0);
});

// REVISED (was: refused CORRECTION_TARIFF_PRICE_MISMATCH for the kept line).
it('accepts a correction that keeps an unchanged line whose price differs from its tariff', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice, '200000'); // Tambal kept at its original 200.000
    $lines[0]['qty'] = 2;                           // only Scaling changes

    expect(ricSubmit($invoice, lines: $lines)->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// REVISED (was: refused CORRECTION_TARIFF_DISCOUNT). A discount within its line is
// a manual pricing decision; no discount cap is invented (D21c pending).
it('accepts a discount within its line on a correction line', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[0]['discount'] = '1000';

    expect((float) ricSubmit($invoice, lines: $lines)->proposed_grand_total)->toEqual(349000.0);
});

// REVISED (was: refused CORRECTION_TARIFF_FREE_TEXT). Free-text lines are a live
// cashier workflow on the original invoice; a correction may add one too.
it('accepts an added free-text line', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[] = ['description' => 'Biaya tambahan manual', 'qty' => 1, 'unit_price' => '10000', 'discount' => '0'];

    expect((float) ricSubmit($invoice, lines: $lines)->proposed_grand_total)->toEqual(360000.0);
});

// REVISED (was: refused CORRECTION_TARIFF_UNVERIFIED). A branch without a tariff
// corrects at the typed price — and still never borrows another branch's tariff.
it('accepts a correction in a branch without any tariff, using the typed price and never another branch\'s tariff', function () {
    $branch = Branch::factory()->create(['code' => 'IC05', 'is_rme_enabled' => true, 'is_active' => true]);
    $invoice = ricInvoice(ricVisit($branch));
    Tariff::query()->where('branch_id', $branch->id)->delete(); // IC01 still holds tariffs for both treatments

    $correction = ricApply(ricApprove(ricSubmit($invoice, lines: ricLinesReducing($invoice, '65000'))));
    $tambal = RmeInvoiceItem::query()->where('rme_invoice_id', $invoice->id)->where('treatment_id', test()->treatment2->id)->sole();

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $tambal->unit_price)->toEqual(65000.0)
        ->and($tambal->tariff_id)->toBeNull();
});

// REVISED (was: "refuses a zero tariff as price authority", matching any
// CORRECTION_ code). A zero tariff is irrelevant now; the Rp0 LINE is what is
// refused, by the Rp0 rule (rule 190 §10), whatever the tariff says.
it('refuses an Rp0 line whatever the tariff says (Rp0 rule, not tariff authority)', function () {
    $invoice = ricInvoice();
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment2->id)->update(['price' => '0.00']);

    rctExpectRefused(fn () => ricSubmit($invoice, lines: ricLinesReducing($invoice, '0')), 'CORRECTION_RP0_NOT_PERMITTED');
});

// ─── A tariff change never makes a correction stale ───────────────────────

// REVISED (was: STALE TARIFF_AUTHORITY_CHANGED at approval).
it('approves normally when a tariff changed since submission', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment2->id)->update(['price' => '60000.00']);

    expect(ricApprove($correction)->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED)
        ->and($correction->fresh()->stale_reason_code)->toBeNull();
});

// REVISED (was: STALE TARIFF_AUTHORITY_CHANGED at application).
it('applies normally when a tariff was withdrawn after approval', function () {
    $invoice = ricInvoice();
    $correction = ricApprove(ricSubmit($invoice));
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment->id)->update(['is_active' => false]);

    expect(ricApply($correction)->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0);
});

// REVISED (was: applied lines stamped TARIFF_MATCHED with the tariff recorded).
it('stamps the changed lines CORRECTION_MANUAL_ENTRY and records no tariff', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));

    $tambal = RmeInvoiceItem::query()->where('rme_invoice_id', $invoice->id)->where('treatment_id', test()->treatment2->id)->sole();
    expect($tambal->price_source)->toBe(RmeInvoicePriceSource::CORRECTION_MANUAL_ENTRY)
        ->and($tambal->tariff_unit_price)->toBeNull()
        ->and($tambal->tariff_id)->toBeNull();
});

it('keeps a kept line valid after its treatment was deactivated (only its tariff on the invoice date matters)', function () {
    $invoice = ricInvoice();
    test()->treatment->forceFill(['is_active' => false])->save(); // Scaling, kept unchanged

    $correction = ricSubmit($invoice);
    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── D1 — approval authority contract ──────────────────────────────────────

/** A context-bound account (Kasir / Admin Klinik style) pinned to $branch, holding $permissions. */
function rctPinned(Branch $branch, array $permissions, string $role): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_merge($permissions, ['view_clinic_visits']));
    $role === 'Kasir' ? rmeMakeKasirActive($user, $branch) : rmeMakeAdminClinicActive($user, $branch);

    return $user->fresh();
}

it('D1: lets a branch Kasir submit and that branch\'s Admin Klinik approve', function () {
    $kasir = rctPinned(test()->branch, [RmeInvoiceCorrectionPermission::REQUEST, 'manage_rme_billing'], 'Kasir');
    $admin = rctPinned(test()->branch, [RmeInvoiceCorrectionPermission::APPROVE], 'Admin Klinik');

    $correction = ricSubmit(ricInvoice(), $kasir);
    expect(ricApprove($correction, $admin)->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED);
});

it('D1: refuses an Admin Klinik of another branch', function () {
    $correction = ricSubmit(ricInvoice());
    $elsewhere = Branch::factory()->create(['code' => 'IC06', 'is_rme_enabled' => true, 'is_active' => true]);
    $admin = rctPinned($elsewhere, [RmeInvoiceCorrectionPermission::APPROVE], 'Admin Klinik');

    expect(fn () => ricApprove($correction, $admin))->toThrow(RmeInvoiceCorrectionDenied::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('D1: lets Super Admin approve across branches, but never its own request', function () {
    $superAdmin = userInRole('Super Admin');
    $branch = Branch::factory()->create(['code' => 'IC07', 'is_rme_enabled' => true, 'is_active' => true]);

    $theirs = ricSubmit(ricInvoice(ricVisit($branch)));
    expect(ricApprove($theirs, $superAdmin)->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED);

    $own = ricSubmit(ricInvoice(), $superAdmin);
    expect(fn () => ricApprove($own, $superAdmin))->toThrow(RmeInvoiceCorrectionDenied::class);
});

it('D1: grants no correction permission to Kasir or Admin Klinik yet (activation is a separate ceremony)', function () {
    foreach (['Kasir', 'Admin Klinik'] as $name) {
        $role = Role::findByName($name);
        foreach (RmeInvoiceCorrectionPermission::OPERATIONAL as $permission) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$name} unexpectedly holds {$permission}");
        }
    }
});
