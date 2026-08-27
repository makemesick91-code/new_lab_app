<?php

// FIX-RME-PRINT-REMOVE-PATIENT-VISIT-DUPLICATE-1 — the RME print composition
// drops the "Data Pasien & Kunjungan" card.
//
// The card restated, as typed metadata, information the clinician has already
// written by hand on the RM sheet that the very same document reproduces
// immediately below it. Printing both made the first page a duplicate of the
// second, so "Cetak RME" now opens on the clinical content.
//
// Scope is the print COMPOSITION only. Nothing is deleted from the patient,
// the visit or the medical record; the visit detail screen still shows every
// one of these fields, and the document keeps its own identification (the
// title carries the patient and visit number, the footer carries the visit
// number) because that is a document header, not the duplicated card.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->viewer = userWith(['view_clinic_visits']);
});

/**
 * A visit whose every removed-card field carries a marker unique to the
 * fixture. Asserting on the markers rather than on the label text alone means
 * the test fails if the DATA comes back under any other heading, not merely if
 * this particular caption is renamed.
 */
function duplicateCardVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    $treatment = Treatment::factory()->create(['name' => 'DUPCARD_INITIAL_TREATMENT']);

    $visit = ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'visit_number' => 'VIS-DUPCARD-001',
        'visit_date' => '2026-06-10',
        'queue_number' => 77,
        'chief_complaint' => 'DUPCARD_CHIEF_COMPLAINT',
        'initial_treatment_id' => $treatment->id,
    ], $overrides));

    $visit->patient->forceFill([
        'medical_record_number' => 'DUPCARD-RM-0001',
        'phone' => '081200000001',
        'whatsapp_number' => '081200000002',
    ])->save();

    return $visit->refresh();
}

function duplicateCardVisitWithHandwriting(Branch $branch): ClinicVisit
{
    $visit = duplicateCardVisit($branch);

    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'finalized_at' => now(),
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'handwriting_path' => 'handwritings/test/dupcard.png',
    ]);

    Storage::disk('clinical_evidence')->put('handwritings/test/dupcard.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    return $visit;
}

// ---------------------------------------------------------------------------
// 1–3: the card, its caption and its data are gone from the browser print
// ---------------------------------------------------------------------------

it('rme print no longer renders the data pasien dan kunjungan section title', function () {
    $visit = duplicateCardVisit($this->branch);

    $response = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk();

    // The caption is written as an HTML entity in the template, so assert on
    // the raw markup as well: an escaped-only assertion would pass against
    // `Data Pasien &amp; Kunjungan` even if the heading were still rendered.
    $response->assertDontSee('Data Pasien');
    $response->assertDontSee('Data Pasien &amp; Kunjungan', false);
});

it('rme print no longer renders the duplicated patient and visit field labels', function () {
    $visit = duplicateCardVisit($this->branch);

    $response = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk();

    // Assert the card's own <dt> markup, not the bare words. Two of these words
    // legitimately survive elsewhere and a bare-word assertion would demand
    // their removal too: "Klinik" appears in the clinic logo's alt text
    // ("Logo Klinik Gigi Daengtisia") and "Rekam Medis" is the surviving
    // clinical section heading — branding and document identification, which
    // this sprint keeps. Only the card's labelled cells go.
    //
    // The companion test below asserts the VALUES are gone, so between them a
    // reinstated card is caught whether or not it reuses this markup.
    foreach ([
        'Nama Pasien',
        'No. Rekam Medis',
        'Ponsel',
        'Nomor WA',
        'No. Kunjungan',
        'Tanggal Kunjungan',
        'Cabang',
        'Klinik',
        'Antrian',
        'Dokter',
        'Tindakan Awal',
        'Keluhan Utama',
    ] as $label) {
        $response->assertDontSee('<dt>'.$label.'</dt>', false);
    }
});

it('rme print no longer renders the duplicated patient and visit values', function () {
    $visit = duplicateCardVisit($this->branch);

    // The stronger claim: the DATA is absent, not merely this caption. A
    // relabelled card carrying the same values would still fail here.
    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('DUPCARD-RM-0001')
        ->assertDontSee('081200000001')
        ->assertDontSee('081200000002')
        ->assertDontSee('DUPCARD_CHIEF_COMPLAINT')
        ->assertDontSee('DUPCARD_INITIAL_TREATMENT')
        ->assertDontSee('#77')
        ->assertDontSee('10/06/2026');
});

// ---------------------------------------------------------------------------
// 4–5: what the document must still do
// ---------------------------------------------------------------------------

it('rme print still renders the handwritten rme content inline', function () {
    Storage::fake('public');
    Storage::fake('clinical_evidence');

    $visit = duplicateCardVisitWithHandwriting($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Rekam Medis')
        ->assertSee('RME Tulisan Tangan')
        // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — still embedded, still not linked.
        ->assertSee('data:image/png;base64,', false);
});

it('rme print keeps identifying its own document in the title and footer', function () {
    // Removing the duplicated CARD must not strip the document's identity. The
    // browser tab/print header and the page footer are a document header, not
    // the card, and they are what makes a printed sheet attributable.
    $visit = duplicateCardVisit($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee($visit->patient->name)
        ->assertSee('VIS-DUPCARD-001')
        ->assertSee('Rekam Medis Elektronik');
});

// ---------------------------------------------------------------------------
// 6: no visual residue where the card used to be
// ---------------------------------------------------------------------------

it('rme print leaves no empty card container where the section was removed', function () {
    $visit = duplicateCardVisitWithHandwriting($this->branch);

    $html = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->getContent();

    // An emptied wrapper would still paint a bordered heading strip and reserve
    // vertical space, which is exactly the residue this sprint must not leave.
    expect($html)->not->toMatch('/<div class="section-block">\s*<\/div>/')
        ->and($html)->not->toMatch('/<div class="info-grid">\s*<\/div>/');

    // And the clinical section is now the document's opening section: nothing
    // else stands between the header and the medical record.
    $body = substr($html, (int) strpos($html, '<body>'));
    $firstSectionTitle = strpos($body, 'class="section-title"');
    expect($firstSectionTitle)->not->toBeFalse();
    expect(substr($body, $firstSectionTitle, 200))->toContain('Rekam Medis');
});

// ---------------------------------------------------------------------------
// 7: the PDF export drops the same card
// ---------------------------------------------------------------------------

it('rme pdf export no longer renders the duplicated patient and visit card', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    // Deliberately WITHOUT a handwriting image: rasterising one would make this
    // test depend on the GD extension, and the claim under test is about the
    // removed card, not about image embedding (which test 4 already covers).
    $visit = duplicateCardVisit($this->branch);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'finalized_at' => now(),
    ]);

    $response = $this->actingAs($this->viewer)->get(route('rme.visits.pdf', $visit));
    $response->assertOk();

    // Section titles are CSS-uppercased on the way into the PDF.
    $text = strtoupper(pdfExtractText($response->getContent()));

    // A negative assertion over a PDF is vacuous unless extraction is shown to
    // work, so prove the extractor really read THIS document first — using an
    // anchor that survives the removal.
    expect($text)->toContain('REKAM MEDIS');

    expect($text)->not->toContain('DATA PASIEN')
        ->and($text)->not->toContain('DUPCARD-RM-0001')
        ->and($text)->not->toContain('DUPCARD_CHIEF_COMPLAINT')
        ->and($text)->not->toContain('KELUHAN UTAMA');
});

// ---------------------------------------------------------------------------
// 8–9: authorization and branch isolation are untouched
// ---------------------------------------------------------------------------

it('rme print authorization is unchanged by the removal', function () {
    $visit = duplicateCardVisit($this->branch);

    $this->actingAs(userWith([]))
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();

    $this->actingAs(userWith([]))
        ->get(route('rme.visits.pdf', $visit))
        ->assertForbidden();
});

it('rme print branch isolation is unchanged by the removal', function () {
    $otherBranch = Branch::factory()->create(['code' => 'DUPCARD-XBR', 'is_rme_enabled' => false]);
    $visit = duplicateCardVisit($otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.pdf', $visit))
        ->assertForbidden();
});
