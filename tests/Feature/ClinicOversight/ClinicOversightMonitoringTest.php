<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — dental room,
| billed-treatment and queue monitoring.
*/

use App\Modules\ClinicOversight\Services\OversightMonitoringService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('MNA', 'Cabang Monitor A');
    $this->branchB = covBranch('MNB', 'Cabang Monitor B');
    $this->treatmentRoom = covRoom($this->branchA, ClinicRoom::TYPE_TREATMENT_ROOM, name: 'Ruang Perawatan 1');
    $this->consultRoom = covRoom($this->branchB, ClinicRoom::TYPE_CONSULTATION_ROOM, name: 'Ruang Konsultasi 1');
    $this->sterilRoom = covRoom($this->branchA, ClinicRoom::TYPE_STERILIZATION_ROOM, name: 'Ruang Sterilisasi');
    $this->labRoom = covRoom($this->branchA, ClinicRoom::TYPE_LAB_ROOM, name: 'Ruang Laboratorium');
    $this->inactiveRoom = covRoom($this->branchA, ClinicRoom::TYPE_TREATMENT_ROOM, ClinicRoom::STATUS_INACTIVE, 'Ruang Tutup');
    $this->today = clinicalToday()->toDateString();

    covActingAs(covUser('Finance Klinik'));
});

function covItem(RmeInvoice $invoice, ?Treatment $treatment, string $subtotal, string $description = 'Item'): void
{
    RmeInvoiceItem::query()->forceCreate([
        'rme_invoice_id' => $invoice->id,
        'treatment_id' => $treatment?->id,
        'description' => $description,
        'qty' => 1,
        'unit_price' => $subtotal,
        'discount' => 0,
        'subtotal' => $subtotal,
    ]);
}

it('lists only ACTIVE treatment and consultation rooms', function () {
    $this->get(route('oversight.monitoring.rooms'))
        ->assertOk()
        ->assertSee('Ruang Perawatan 1')
        ->assertSee('Ruang Konsultasi 1')
        ->assertDontSee('Ruang Sterilisasi')
        ->assertDontSee('Ruang Laboratorium')
        ->assertDontSee('Ruang Tutup');
});

it('lists patients per dental room for the clinic date with contact and status, excluding non-dental rooms', function () {
    $p = covPatient($this->branchA, ['name' => 'Pasien Ruang Satu', 'phone' => '0811111111']);
    covVisit($this->branchA, $p, ['clinic_room_id' => $this->treatmentRoom->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS]);
    covVisit($this->branchA, covPatient(null, ['name' => 'Pasien Steril']), ['clinic_room_id' => $this->sterilRoom->id]);
    covVisit($this->branchA, covPatient(null, ['name' => 'Pasien Kemarin']), ['clinic_room_id' => $this->treatmentRoom->id, 'visit_date' => clinicalToday()->subDay()->toDateString()]);

    $this->get(route('oversight.monitoring.room-patients'))
        ->assertOk()
        ->assertSee('Pasien Ruang Satu')
        ->assertSee('0811111111')
        ->assertSee('Dalam Pemeriksaan')
        ->assertDontSee('Pasien Steril')
        ->assertDontSee('Pasien Kemarin');

    $this->get(route('oversight.monitoring.room-patients', ['room_id' => $this->consultRoom->id]))
        ->assertOk()->assertDontSee('Pasien Ruang Satu');
});

it('shows BILLED treatments per room from issued invoices only, with an unclassified bucket for free text', function () {
    $scaling = Treatment::factory()->create(['name' => 'Scaling Gigi']);
    $visit = covVisit($this->branchA, covPatient(), ['clinic_room_id' => $this->treatmentRoom->id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $paid = covInvoice($visit, RmeInvoice::STATUS_PAID, '250000');
    covItem($paid, $scaling, '200000');
    covItem($paid, null, '50000', 'Obat kumur racikan');

    $draftVisit = covVisit($this->branchA, covPatient(), ['clinic_room_id' => $this->treatmentRoom->id]);
    covItem(covInvoice($draftVisit, RmeInvoice::STATUS_DRAFT, '900000'), $scaling, '900000');
    $voidVisit = covVisit($this->branchA, covPatient(), ['clinic_room_id' => $this->treatmentRoom->id]);
    covItem(covInvoice($voidVisit, RmeInvoice::STATUS_VOID, '800000'), $scaling, '800000');

    $summary = app(OversightMonitoringService::class)->roomTreatmentSummary(auth()->user(), []);

    expect($summary->sum('item_count'))->toBe(2)
        ->and((int) round($summary->sum('total_amount')))->toBe(250000);

    $this->get(route('oversight.monitoring.room-treatments'))
        ->assertOk()
        ->assertSee('JENIS PERAWATAN DITAGIHKAN')
        ->assertSee('Scaling Gigi')
        ->assertSee(OversightMonitoringService::UNCLASSIFIED_TREATMENT_LABEL)
        ->assertSee('Obat kumur racikan')
        ->assertDontSee('Rp 900.000')
        ->assertDontSee('Rp 800.000');
});

it('shows the queue of every branch for the date and keeps stale unfinished visits separate', function () {
    covVisit($this->branchA, covPatient(null, ['name' => 'Antrian A Satu']), ['queue_number' => 1, 'status' => ClinicVisit::STATUS_WAITING]);
    covVisit($this->branchB, covPatient(null, ['name' => 'Antrian B Satu']), ['queue_number' => 1, 'status' => ClinicVisit::STATUS_REGISTERED, 'check_in_at' => null]);
    covVisit($this->branchA, covPatient(null, ['name' => 'Tertunda Lama']), [
        'visit_date' => clinicalToday()->subDays(3)->toDateString(),
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
    ]);

    $byBranch = collect(app(OversightMonitoringService::class)->queueByBranch(auth()->user(), []));
    $rowA = $byBranch->first(fn ($r) => $r['branch']->id === $this->branchA->id);

    expect($rowA['total'])->toBe(1)
        ->and($rowA['stale_unfinished'])->toBe(1)
        ->and($rowA['counts'][ClinicVisit::STATUS_WAITING])->toBe(1);

    $this->get(route('oversight.monitoring.queue'))
        ->assertOk()->assertSee('Antrian A Satu')->assertSee('Antrian B Satu')->assertDontSee('Tertunda Lama');

    $this->get(route('oversight.monitoring.queue.branches', ['branch_id' => $this->branchB->id]))
        ->assertOk()->assertSee('Antrian B Satu')->assertDontSee('Antrian A Satu');
});

it('renders monitoring without touching any online-context or visit row', function () {
    covVisit($this->branchA, covPatient(), ['clinic_room_id' => $this->treatmentRoom->id]);
    $contexts = DB::table('trx_user_online_contexts')->count();
    $visitsUpdated = DB::table('trx_clinic_visits')->max('updated_at');

    foreach (['oversight.monitoring.rooms', 'oversight.monitoring.room-patients', 'oversight.monitoring.room-treatments', 'oversight.monitoring.queue', 'oversight.monitoring.queue.branches'] as $name) {
        $this->get(route($name))->assertOk();
    }

    expect(DB::table('trx_user_online_contexts')->count())->toBe($contexts)
        ->and(DB::table('trx_clinic_visits')->max('updated_at'))->toBe($visitsUpdated);
});

it('exports the queue as CSV with a generic filename and no NIK column', function () {
    covVisit($this->branchA, covPatient(null, ['name' => 'Pasien Ekspor', 'ktp_number' => '7371010000000001']), ['queue_number' => 7]);

    $response = $this->get(route('oversight.monitoring.queue.export'))->assertOk();
    $csv = $response->streamedContent();

    expect((string) $response->headers->get('content-disposition'))->toContain('antrian-')->not->toContain('Pasien')
        ->and($csv)->toContain('Pasien Ekspor')
        ->and($csv)->not->toContain('7371010000000001')
        ->and($csv)->not->toContain('NIK');
});
