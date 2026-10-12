<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — read-only native
| RME and PUBLISHED legacy archives across every RME branch.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('CRA', 'Cabang Klinis A');
    $this->branchB = covBranch('CRB', 'Cabang Klinis B');
    $this->main = covBranch('MAIN', 'Kantor Pusat');

    $this->patient = covPatient($this->branchA, ['name' => 'Pasien Lintas Cabang']);
    $this->visit = covVisit($this->branchB, $this->patient, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branchB->id,
        'patient_id' => $this->patient->id,
        'notes' => 'Pemeriksaan rutin gigi 11',
        'status' => MedicalRecord::STATUS_FINAL,
    ]);

    $this->legacyOnly = covPatient($this->branchA, ['name' => 'Pasien Arsip Saja']);
    $this->legacy = LegacyRmeRecord::factory()->create([
        'patient_id' => $this->legacyOnly->id,
        'origin_branch_id' => $this->branchA->id,
        'title' => 'Arsip 2019',
    ]);

    $this->mainOnly = covPatient($this->main, ['name' => 'Pasien Pusat Saja']);
    $mainVisit = covVisit($this->main, $this->mainOnly);
    MedicalRecord::factory()->create(['clinic_visit_id' => $mainVisit->id, 'branch_id' => $this->main->id, 'patient_id' => $this->mainOnly->id]);

    covActingAs(covUser('Data Analyst'));
});

it('lists native and legacy-only patients across RME branches, never a MAIN-only patient', function () {
    $this->get(route('oversight.rme.index'))
        ->assertOk()
        ->assertSee('Pasien Lintas Cabang')
        ->assertSee('Pasien Arsip Saja')
        ->assertDontSee('Pasien Pusat Saja');
});

it('narrows the index by branch and fails closed on a forged branch', function () {
    $this->get(route('oversight.rme.index', ['branch_id' => $this->branchB->id]))
        ->assertOk()->assertSee('Pasien Lintas Cabang')->assertDontSee('Pasien Arsip Saja');

    $this->get(route('oversight.rme.index', ['branch_id' => $this->main->id]))
        ->assertOk()->assertDontSee('Pasien Lintas Cabang')->assertDontSee('Pasien Arsip Saja');
});

it('opens the patient RME history with native visits and PUBLISHED legacy archives only', function () {
    $void = LegacyRmeRecord::factory()->voided()->create([
        'patient_id' => $this->patient->id,
        'origin_branch_id' => $this->branchA->id,
        'title' => 'Arsip Dibatalkan',
    ]);
    LegacyRmeRecord::factory()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->branchA->id, 'title' => 'Arsip Sah']);

    $this->get(route('oversight.rme.patients.show', $this->patient->id))
        ->assertOk()
        ->assertSee($this->visit->visit_number)
        ->assertSee('Arsip Sah')
        ->assertDontSee('Arsip Dibatalkan')
        ->assertDontSee(route('oversight.legacy.rme.show', $void->id));

    $this->get(route('oversight.rme.patients.show', $this->mainOnly->id))->assertNotFound();
    $this->get(route('oversight.rme.patients.show', 999999))->assertNotFound();
});

it('resolves the visit-number and Nomor RM lookups only inside the scope, by exact match', function () {
    $this->get(route('oversight.rme.lookup', ['visit_number' => $this->visit->visit_number]))
        ->assertRedirect(route('oversight.rme.visits.show', $this->visit->id));

    $this->get(route('oversight.rme.lookup', ['visit_number' => substr($this->visit->visit_number, 0, 6)]))
        ->assertOk()->assertSee('tidak ditemukan');

    $mainVisit = ClinicVisit::query()->where('branch_id', $this->main->id)->first();
    $this->get(route('oversight.rme.lookup', ['visit_number' => $mainVisit->visit_number]))
        ->assertOk()->assertSee('tidak ditemukan');

    $this->get(route('oversight.rme.lookup', ['mode' => 'patient', 'medical_record_number' => $this->patient->medical_record_number]))
        ->assertRedirect(route('oversight.rme.patients.show', $this->patient->id));

    $this->get(route('oversight.rme.lookup', ['mode' => 'patient', 'medical_record_number' => $this->mainOnly->medical_record_number]))
        ->assertOk()->assertSee('tidak ditemukan');
});

it('renders the visit detail read-only with the RM sheet, odontogram, and invoice balance', function () {
    Odontogram::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branchB->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);
    $invoice = covInvoice($this->visit, RmeInvoice::STATUS_PARTIAL, '300000');
    covPayment($invoice, '100000.00', '2026-10-10 02:00:00');

    $response = $this->get(route('oversight.rme.visits.show', $this->visit->id))
        ->assertOk()
        ->assertSee('Pemeriksaan rutin gigi 11')
        ->assertSee($invoice->invoice_number)
        ->assertSee('Rp 200.000')
        ->assertSee('DMF-T');

    // No form, no edit/transition control on the page.
    $html = $response->getContent();
    // The only POST form on the page is the layout's own logout.
    preg_match_all('/<form[^>]*method="post"[^>]*action="([^"]+)"/i', $html, $posts);
    expect(array_values(array_unique($posts[1])))->toBe([route('logout')]);
    expect($html)->not->toContain(route('rme.visits.transition', $this->visit))
        ->and($html)->not->toContain('name="_method"');
});

it('prints and exports the visit through the shared native print template with oversight links', function () {
    $print = $this->get(route('oversight.rme.visits.print', $this->visit->id))->assertOk();
    $print->assertSee(route('oversight.rme.visits.pdf', $this->visit->id), false)
        ->assertSee(route('oversight.rme.visits.show', $this->visit->id), false)
        ->assertDontSee(route('rme.visits.show', $this->visit), false);

    $pdf = $this->get(route('oversight.rme.visits.pdf', $this->visit->id))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and((string) $pdf->headers->get('content-disposition'))->not->toContain($this->patient->name);
});

it('prints the odontogram only when a chart is saved', function () {
    $this->get(route('oversight.rme.visits.odontogram.print', $this->visit->id))->assertNotFound();

    Odontogram::factory()->create(['clinic_visit_id' => $this->visit->id, 'branch_id' => $this->branchB->id]);

    $this->get(route('oversight.rme.visits.odontogram.print', $this->visit->id))
        ->assertOk()
        ->assertSee(route('oversight.rme.visits.show', $this->visit->id), false);
});

it('views, streams, prints and exports a PUBLISHED legacy RME archive', function () {
    Storage::fake('legacy_rme_private');
    Storage::disk('legacy_rme_private')->put('rme-legacy/p1/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_rme_private')->put('rme-legacy/p1/source.pdf', '%PDF-1.4 fake');
    $this->legacy->forceFill(['source_disk' => 'legacy_rme_private', 'source_pdf_path' => 'rme-legacy/p1/source.pdf'])->save();
    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $this->legacy->id,
        'page_number' => 1,
        'background_disk' => 'legacy_rme_private',
        'background_path' => 'rme-legacy/p1/page-1.png',
    ]);

    $this->get(route('oversight.legacy.rme.show', $this->legacy->id))->assertOk()->assertSee('Arsip 2019');
    $this->get(route('oversight.legacy.rme.pages.show', [$this->legacy->id, 1]))->assertOk();
    $this->get(route('oversight.legacy.rme.source', $this->legacy->id))->assertOk();
    $this->get(route('oversight.legacy.rme.print', $this->legacy->id))
        ->assertOk()
        ->assertSee(route('oversight.legacy.rme.pages.show', [$this->legacy->id, 1]), false)
        ->assertDontSee(route('rme.legacy-records.pages.show', [$this->legacy->id, 1]), false);
    $this->get(route('oversight.legacy.rme.pdf', $this->legacy->id))->assertOk();

    expect($this->legacy->fresh()->isPublished())->toBeTrue();
});

it('404s a VOID, a MAIN-branch, or a branchless legacy RME archive on every surface', function () {
    $void = LegacyRmeRecord::factory()->voided()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->branchA->id]);
    $main = LegacyRmeRecord::factory()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->main->id]);
    $branchless = LegacyRmeRecord::factory()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => null]);

    foreach ([$void, $main, $branchless] as $record) {
        foreach (['oversight.legacy.rme.show', 'oversight.legacy.rme.source', 'oversight.legacy.rme.print', 'oversight.legacy.rme.pdf'] as $name) {
            $this->get(route($name, $record->id))->assertNotFound();
        }
        $this->get(route('oversight.legacy.rme.pages.show', [$record->id, 1]))->assertNotFound();
    }
});

it('views and streams a PUBLISHED legacy odontogram and 404s a VOID one', function () {
    Storage::fake('legacy_odontogram_private');
    Storage::disk('legacy_odontogram_private')->put('odo/p1/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_odontogram_private')->put('odo/p1/source.pdf', '%PDF-1.4 fake');

    $record = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'branch_id' => $this->branchA->id,
        'source_pdf_path' => 'odo/p1/source.pdf',
        'page_count' => 1,
        'title' => 'Odontogram 2018',
    ]);
    LegacyOdontogramRecordPage::query()->forceCreate([
        'odontogram_legacy_record_id' => $record->id,
        'page_number' => 1,
        'width' => 100,
        'height' => 100,
        'dpi' => 150,
        'rotation' => 0,
        'image_disk' => 'legacy_odontogram_private',
        'image_path' => 'odo/p1/page-1.png',
        'image_sha256' => str_repeat('b', 64),
    ]);

    $this->get(route('oversight.legacy.odontogram.show', $record->id))->assertOk()->assertSee('Odontogram 2018');
    $this->get(route('oversight.legacy.odontogram.pages.show', [$record->id, 1]))->assertOk();
    $this->get(route('oversight.legacy.odontogram.source', $record->id))->assertOk();
    $this->get(route('oversight.legacy.odontogram.print', $record->id))->assertOk();

    $record->forceFill(['status' => 'VOID'])->save();
    $this->get(route('oversight.legacy.odontogram.show', $record->id))->assertNotFound();
    $this->get(route('oversight.legacy.odontogram.pages.show', [$record->id, 1]))->assertNotFound();
});

it('neutralises spreadsheet formula injection in the RME CSV export', function () {
    covPatient($this->branchB, ['name' => '=HYPERLINK("http://evil")']);
    $this->patient->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();

    $csv = $this->get(route('oversight.rme.export'))->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toMatch('/(^|,)"?=HYPERLINK/m');
});
