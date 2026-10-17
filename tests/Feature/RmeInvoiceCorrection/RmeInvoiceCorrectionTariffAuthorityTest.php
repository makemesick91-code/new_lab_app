<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — invoice correction under owner
| decisions D21(4) and D1.
|--------------------------------------------------------------------------
|
| D21(4): "a branch must not activate invoice correction for treatment items
| whose prices cannot be validated against approved tariff authority". Every
| line of the RESULTING document must be a treatment billed at exactly its
| branch tariff in force on the invoice date, with no discount. A tariff that
| moves after submission makes the correction STALE (TARIFF_AUTHORITY_CHANGED).
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
use App\Modules\Treatment\Models\Treatment;
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

it('refuses a correction that moves a line away from its tariff', function () {
    $invoice = ricInvoice();
    rctExpectRefused(fn () => ricSubmit($invoice, lines: ricLinesReducing($invoice, '45000')), 'CORRECTION_TARIFF_PRICE_MISMATCH');
});

it('refuses a correction that keeps an unchanged line which deviates from its tariff', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice, '200000'); // Tambal kept at the transitional 200.000
    $lines[0]['qty'] = 2;                           // only Scaling changes

    rctExpectRefused(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TARIFF_PRICE_MISMATCH');
});

it('refuses a discount on a correction line (exceptions are undecided)', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[0]['discount'] = '1000';

    rctExpectRefused(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TARIFF_DISCOUNT');
});

it('refuses a free-text line, which no tariff can validate', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[] = ['description' => 'Biaya tambahan manual', 'qty' => 1, 'unit_price' => '10000', 'discount' => '0'];

    rctExpectRefused(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TARIFF_FREE_TEXT');
});

it('refuses a correction in a branch whose tariff for the treatment does not exist (no cross-branch fallback)', function () {
    $branch = Branch::factory()->create(['code' => 'IC05', 'is_rme_enabled' => true, 'is_active' => true]);
    $invoice = ricInvoice(ricVisit($branch));
    Tariff::query()->where('branch_id', $branch->id)->delete(); // IC01 still holds tariffs for both treatments

    rctExpectRefused(fn () => ricSubmit($invoice), 'CORRECTION_TARIFF_UNVERIFIED');
});

it('refuses a zero tariff as price authority', function () {
    $invoice = ricInvoice();
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment2->id)->update(['price' => '0.00']);

    rctExpectRefused(fn () => ricSubmit($invoice, lines: ricLinesReducing($invoice, '0')), 'CORRECTION_');
});

// ─── D21(4) — the authority must still hold at approval and application ───

it('marks the correction STALE at approval when the tariff changed since submission', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment2->id)->update(['price' => '60000.00']);

    rctExpectRefused(fn () => ricApprove($correction), 'CORRECTION_STALE:TARIFF_AUTHORITY_CHANGED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and($correction->fresh()->stale_reason_code)->toBe('TARIFF_AUTHORITY_CHANGED');
});

it('marks the correction STALE at application when the tariff was withdrawn after approval', function () {
    $invoice = ricInvoice();
    $correction = ricApprove(ricSubmit($invoice));
    Tariff::query()->where('branch_id', test()->branch->id)->where('treatment_id', test()->treatment->id)->update(['is_active' => false]);

    rctExpectRefused(fn () => ricApply($correction), 'CORRECTION_STALE:TARIFF_AUTHORITY_CHANGED');
    expect((float) $invoice->fresh()->grand_total)->toEqual(500000.0);
});

it('stamps the applied lines with their tariff provenance', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));

    $tambal = RmeInvoiceItem::query()->where('rme_invoice_id', $invoice->id)->where('treatment_id', test()->treatment2->id)->sole();
    expect($tambal->price_source)->toBe(RmeInvoicePriceSource::TARIFF_MATCHED)
        ->and((float) $tambal->tariff_unit_price)->toEqual(50000.0)
        ->and($tambal->tariff_id)->not->toBeNull();
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
