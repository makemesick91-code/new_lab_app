<?php

declare(strict_types=1);

/*
| HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
| RP0-REV-26: the read-only "Tanpa Biaya (Rp0)" oversight attributes every closure
| to the right closing cashier, invoice creator, doctor and branch, inside the
| billing-correction oversight area (same gate, permission and branch scope), with
| no patient identity on the page and no way to write anything.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Middleware\PermissionMiddleware;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rzvSetup();

    $kasir = User::factory()->create(['name' => 'Kasir Kedua Rp0']);
    rmeMakeKasirActive($kasir, test()->branch);
    test()->secondCashier = $kasir;

    // One Konsultasi closed by the cashier who issued it, one Kontrol closed by another.
    test()->consultationClosed = rzvClose(rzvConsultationInvoice());
    test()->controlClosed = rzvClose(rzvInvoice(rzvControlVisit(), [rzvLine(test()->consultation)]), $kasir);
});

function rzoPage(string $role, array $query = [])
{
    return test()->actingAs(userInRole($role))->get(route('oversight.billing-corrections.zero-value-closures.index', $query));
}

it('RP0-REV-26: attributes each closure to the right closing cashier, invoice creator, doctor and branch', function (string $role) {
    $page = rzoPage($role)->assertOk()
        ->assertSee('data-oversight-zero-value-summary', false)
        ->assertSee(test()->consultationClosed->invoice_number)
        ->assertSee(test()->controlClosed->invoice_number)
        ->assertSee(test()->consultationClosed->clinicVisit->visit_number)
        ->assertSee(test()->doctor->name)
        ->assertSee(test()->cashier->name)
        ->assertSee(test()->secondCashier->name)
        ->assertSee(test()->branch->name);

    $html = $page->getContent();

    expect($html)->toMatch('/data-zv-total>\s*2\s*</')
        ->and($html)->toMatch('/data-zv-consultations>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-controls>\s*1\s*</')
        // Only the Konsultasi was issued and closed by the same account.
        ->and($html)->toMatch('/data-zv-self-issued>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-payment-anomaly>\s*0\s*</')
        ->and(substr_count($html, 'data-zv-self-issued-row'))->toBe(1);
})->with(['Owner', 'Finance Klinik', 'Data Analyst']);

it('narrows by category and by closing cashier, and a crafted branch id outside the scope narrows to nothing', function () {
    expect(rzoPage('Owner', ['category' => RmeZeroValueCategory::CONTROL])->getContent())
        ->toMatch('/data-zv-total>\s*1\s*</')
        ->toContain(test()->controlClosed->invoice_number)
        ->not->toContain(test()->consultationClosed->invoice_number);

    expect(rzoPage('Owner', ['closer_id' => test()->secondCashier->id])->getContent())
        ->toMatch('/data-zv-total>\s*1\s*</')
        ->toContain(test()->controlClosed->invoice_number);

    expect(rzoPage('Owner', ['branch_id' => 999999])->getContent())
        ->toMatch('/data-zv-total>\s*0\s*</')
        ->not->toContain(test()->controlClosed->invoice_number);
});

it('shows no patient identity — invoice and visit references only', function () {
    $html = rzoPage('Owner')->getContent();

    expect($html)->not->toContain(test()->patient->name)
        ->and($html)->not->toContain((string) test()->patient->medical_record_number);
});

it('surfaces a NO_CHARGE invoice that carries a payment row as an anomaly', function () {
    $closed = test()->controlClosed;
    RmePayment::factory()->create([
        'rme_invoice_id' => $closed->id, 'branch_id' => $closed->branch_id,
        'clinic_visit_id' => $closed->clinic_visit_id, 'patient_id' => $closed->patient_id, 'amount' => 1000,
    ]);

    expect(rzoPage('Owner')->getContent())->toMatch('/data-zv-payment-anomaly>\s*1\s*</');
});

it('is closed to a role without the oversight permission and is GET-only', function () {
    test()->actingAs(test()->cashier)->get(route('oversight.billing-corrections.zero-value-closures.index'))->assertForbidden();

    test()->actingAs(userInRole('Owner'))
        ->post(route('oversight.billing-corrections.zero-value-closures.index'))
        ->assertStatus(405);

    expect(DB::table('trx_rme_invoices')->where('status', 'NO_CHARGE')->count())->toBe(2);
});

it('a branch filter narrows to that branch only, even when it is a real authorized RME branch with no closures', function () {
    $other = Branch::factory()->create(['code' => 'ZV02', 'name' => 'Cabang Rp0 Lain', 'is_rme_enabled' => true, 'is_active' => true]);

    expect(rzoPage('Owner', ['branch_id' => $other->id])->getContent())
        ->toMatch('/data-zv-total>\s*0\s*</')
        ->not->toContain(test()->controlClosed->invoice_number)
        ->not->toContain(test()->consultationClosed->invoice_number);

    expect(rzoPage('Owner', ['branch_id' => test()->branch->id])->getContent())
        ->toMatch('/data-zv-total>\s*2\s*</');
});

it('keeps the controller gate as a second layer when the route permission middleware is lifted', function () {
    test()->withoutMiddleware(PermissionMiddleware::class)
        ->actingAs(test()->cashier)
        ->get(route('oversight.billing-corrections.zero-value-closures.index'))
        ->assertForbidden();
});

it('lists only NO_CHARGE invoices — on PostgreSQL the CHECK forbids closure provenance on any other status, elsewhere the status filter still holds', function () {
    $other = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation, 100000, 0, 'Konsultasi')]);
    $write = fn () => DB::table('trx_rme_invoices')->where('id', $other->id)->update([
        'zero_value_category' => RmeZeroValueCategory::CONSULTATION,
        'zero_value_closed_by' => test()->cashier->id,
        'zero_value_closed_at' => now(),
    ]);

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction($write))->toThrow(QueryException::class);

        return;
    }

    $write();

    expect(rzoPage('Owner')->getContent())
        ->toMatch('/data-zv-total>\s*2\s*</')
        ->not->toContain($other->invoice_number);
});
