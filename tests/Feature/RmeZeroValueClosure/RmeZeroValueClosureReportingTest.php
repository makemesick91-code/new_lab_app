<?php

declare(strict_types=1);

/*
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, owner decision S6-D5) — a
| legitimate Rp0 visit counts as a CLINICAL visit with ZERO revenue. Once closed
| "Tanpa Biaya" it is never "belum dibayar", never a receivable, never "Lunas",
| and it is reported separately as Rp0 Konsultasi / Rp0 Kontrol.
*/

use App\Modules\ClinicOversight\Interfaces\OversightMonitoringRepositoryInterface;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rzvSetup());

function rzvOwnerMetrics(): array
{
    return app(OwnerDashboardKpiService::class)->metrics(null, Carbon::now()->subDay(), Carbon::now()->addDay());
}

it('reports closed Rp0 visits separately by category, with zero revenue and no receivable', function () {
    rzvClose(rzvConsultationInvoice());
    rzvClose(rzvInvoice(rzvControlVisit(), [rzvLine(test()->consultation)]));
    $paidInvoice = ricInvoice();
    ricPay($paidInvoice, 500000);

    $metrics = rzvOwnerMetrics();

    expect($metrics['rp0_visits'])->toBe(2)
        ->and($metrics['rp0_consultations'])->toBe(1)
        ->and($metrics['rp0_controls'])->toBe(1)
        ->and((float) $metrics['total_revenue'])->toEqual(500000.0)
        ->and((float) $metrics['active_receivable'])->toEqual(0.0)
        ->and($metrics['unpaid_invoices'])->toBe(0)
        ->and($metrics['total_visits'])->toBeGreaterThanOrEqual(3);
});

it('reports no Rp0 visit until the invoice is actually closed', function () {
    rzvConsultationInvoice();

    expect(rzvOwnerMetrics()['rp0_visits'])->toBe(0);
});

it('stops counting a closed Rp0 invoice as "belum dibayar" on the RME/Lab dashboard (BCF-05)', function () {
    $invoice = rzvConsultationInvoice();
    $service = app(OwnerDashboardRmeLabKpiService::class);

    $before = $service->metrics(null)['rme_invoices_unpaid'];
    rzvClose($invoice);
    $after = $service->metrics(null)['rme_invoices_unpaid'];

    expect($before)->toBe(1)->and($after)->toBe(0);
});

it('never lists a NO_CHARGE invoice as an outstanding receivable of a later visit', function () {
    $closed = rzvClose(rzvConsultationInvoice());
    $later = ricVisit();
    $current = ricInvoice($later);

    $outstanding = app(RmeControlReceivableService::class)->getOutstandingReceivablesForPatientVisit($later, $current);

    expect($outstanding->pluck('id')->all())->not->toContain($closed->id);
});

it('reports a free consultation as "Tanpa Biaya" in doctor performance, never as Lunas', function () {
    rzvClose(rzvConsultationInvoice());

    $report = app(DoctorPerformanceReportService::class)->report(
        ['forced_doctor_id' => (int) test()->doctor->id],
        [],
    );
    $row = collect($report['treatment_breakdown'])->firstWhere('treatment_id', (int) test()->consultation->id);

    expect($row)->not->toBeNull()
        ->and($row['item_count'])->toBe(1)
        ->and($row['paid_item_count'])->toBe(0)
        ->and($row['no_charge_item_count'])->toBe(1)
        ->and(app(DoctorPerformanceReportService::class)->invoiceStatusOptions())->toHaveKey(RmeInvoice::STATUS_NO_CHARGE);
});

it('marks a Rp0 invoice on the handoff board only while the flag is on', function () {
    rzvConsultationInvoice();
    $board = fn () => $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.handoff'));

    $board()->assertOk()->assertSee('data-handoff-zero-value', false);

    rzvFlag(false);
    $board()->assertOk()->assertDontSee('data-handoff-zero-value', false);
});

it('keeps a closed Rp0 treatment visible in the oversight room view of the day it was performed', function () {
    $invoice = rzvConsultationInvoice();
    DB::table('mst_clinic_rooms')->where('id', $invoice->clinicVisit->clinic_room_id)->update(['type' => ClinicRoom::TYPE_CONSULTATION_ROOM]);
    $items = fn () => app(OversightMonitoringRepositoryInterface::class)
        ->roomTreatments([(int) test()->branch->id], clinicalToday()->toDateString(), [], null);

    expect($items()->pluck('invoice_number')->all())->toContain($invoice->invoice_number);

    rzvClose($invoice);

    $row = $items()->firstWhere('invoice_number', $invoice->invoice_number);
    expect($row)->not->toBeNull()
        ->and($row->invoice_status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and((float) $row->subtotal)->toEqual(0.0);
});

it('flags a NO_CHARGE invoice that somehow carries a payment row as a DQ1 anomaly', function () {
    $closed = rzvClose(rzvConsultationInvoice());
    $count = function (): int {
        Artisan::call('data-quality:dq1-audit', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (int) collect($payload['checks'])->firstWhere('check_id', 'DQ1-DATA-004')['details']['invalid_count'];
    };

    $before = $count();
    RmePayment::factory()->create([
        'rme_invoice_id' => $closed->id, 'branch_id' => $closed->branch_id,
        'clinic_visit_id' => $closed->clinic_visit_id, 'patient_id' => $closed->patient_id, 'amount' => 1000,
    ]);

    expect($count())->toBe($before + 1);
});
