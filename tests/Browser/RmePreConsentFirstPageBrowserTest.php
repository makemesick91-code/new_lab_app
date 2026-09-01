<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1 — the proof in a real browser.
 *
 * The feature suite proves the CONTROLLER stops passing an actionable create to
 * the template. It cannot prove what the doctor is actually looking at, and the
 * bug was precisely a rendering claim: a live button over an act the server
 * refuses. So the claim is made here through Chrome, against the real routes,
 * the real Blade template and a real PostgreSQL 16 database — the production
 * driver.
 *
 * Nothing is stubbed. "Not actionable" is proven by asking Selenium whether the
 * element is enabled, not by grepping for a label — a disabled button still
 * says "Buat Halaman RM Pertama", and that is the state we want. The consent is
 * signed through RmeVisitConsentService::sign() rather than by drawing on the
 * canvas: the signature CANVAS is not what this test is about, and the service
 * is the real signing path.
 *
 * Every fixture is namespaced by MARKER and removed in tearDown, so the shared
 * database is left exactly as it was found.
 */
class RmePreConsentFirstPageBrowserTest extends DuskTestCase
{
    private const MARKER = 'DUSK-PRECONSENT-RM1';

    private const OPERATOR_EMAIL = 'dusk-preconsent-rm1@example.test';

    private const OPERATOR_PASSWORD = 'password';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    /**
     * Journey 1 — before the signature: the record is closed, the chart is open.
     */
    public function test_the_first_page_control_is_not_actionable_before_consent(): void
    {
        [$user, $visit] = $this->makeLiveEncounter();

        $this->browse(function (Browser $browser) use ($user, $visit) {
            $this->login($browser, $user);

            $browser->visit(route('rme.visits.medical-record.show', $visit, false))
                ->assertDontSee('Server Error')
                ->assertSee('Belum ada halaman RM tulisan tangan')
                // The reason is named on the page, not left to a failed POST.
                ->assertSee('Persetujuan Tindakan Medis belum ditandatangani');

            // The control is visible — the doctor can see the action exists —
            // and it is genuinely not pressable. Located BY ITS OWN TEXT rather
            // than by "the first disabled button on the page", so an unrelated
            // disabled control elsewhere in the shell can never satisfy this.
            $buttons = $browser->driver->findElements(
                WebDriverBy::xpath("//button[contains(normalize-space(.), 'Buat Halaman RM Pertama')]")
            );

            $this->assertCount(1, $buttons, 'the create control must be rendered exactly once');
            $this->assertFalse(
                $buttons[0]->isEnabled(),
                'the create control must not be pressable before consent',
            );

            // And there is no submittable form behind it at all.
            $this->assertStringNotContainsString(
                'action="'.route('rme.visits.medical-record.store', $visit, false).'"',
                $browser->driver->getPageSource(),
                'no create form may be rendered while the record is closed',
            );

            // The odontogram is NOT dragged down with the record: the previous
            // GO behaviour has to survive this fix, in the same browser session.
            $browser->visit(route('rme.visits.odontogram.show', $visit, false))
                ->assertDontSee('Server Error')
                ->assertSee('Simpan Odontogram');

            // Finishing the examination stays refused.
            $browser->visit(route('rme.visits.show', $visit, false))
                ->assertDontSee('Server Error');

            $this->assertSame(
                ClinicVisit::STATUS_IN_PROGRESS,
                $visit->fresh()->status,
                'nothing on this journey may advance the visit',
            );
            $this->assertSame(
                0,
                MedicalRecord::where('patient_id', $visit->patient_id)->count(),
                'no record may exist while the consent is unsigned',
            );
        });
    }

    /**
     * Journey 2 — after the signature: the control returns and really works.
     */
    public function test_the_first_page_can_be_created_once_the_consent_is_signed(): void
    {
        [$user, $visit] = $this->makeLiveEncounter();

        $this->browse(function (Browser $browser) use ($user, $visit) {
            $this->login($browser, $user);

            $browser->visit(route('rme.visits.medical-record.show', $visit, false))
                ->assertSee('Persetujuan Tindakan Medis belum ditandatangani');

            // Sign through the real service, then come back to the same screen.
            app(RmeVisitConsentService::class)->sign($visit->fresh(), $user, [
                'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
                'consenter_relationship' => 'self',
                'medical_action' => 'Pencabutan gigi 36 ('.self::MARKER.')',
                'treatment_summary' => self::MARKER,
                'documentation_consent' => false,
                'consenter_signature' => $this->signaturePng(),
            ]);

            $browser->visit(route('rme.visits.medical-record.show', $visit, false))
                ->assertDontSee('Server Error')
                ->assertDontSee('Persetujuan Tindakan Medis belum ditandatangani')
                ->assertSee('Buat Halaman RM Pertama');

            // Now it is a real, pressable control — and pressing it works.
            $browser->press('Buat Halaman RM Pertama')
                ->waitForText('Rekam Medis', 15)
                ->assertDontSee('Server Error');

            $this->assertSame(
                1,
                MedicalRecord::where('patient_id', $visit->patient_id)->count(),
                'the first sheet must be created once the consent is signed',
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /** @return array{0: User, 1: ClinicVisit} */
    private function makeLiveEncounter(): array
    {
        $branch = Branch::where('is_active', true)->where('is_rme_enabled', true)->firstOrFail();

        $user = User::factory()->create([
            'name' => self::MARKER.' Dokter',
            'email' => self::OPERATOR_EMAIL,
            'password' => Hash::make(self::OPERATOR_PASSWORD),
            'branch_id' => $branch->id,
        ]);
        $user->givePermissionTo([
            'view_clinic_visits', 'manage_clinic_visits',
            'complete_rme_examination', 'manage_rme_consents', 'view_rme_consents',
        ]);

        $patient = Patient::factory()->create([
            'branch_id' => $branch->id,
            'name' => self::MARKER.' Pasien',
        ]);

        $visit = ClinicVisit::factory()->create([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'chief_complaint' => self::MARKER,
        ]);

        return [$user, $visit];
    }

    private function login(Browser $browser, User $user): void
    {
        $browser->visit('/login')
            ->type('email', $user->email)
            ->type('password', self::OPERATOR_PASSWORD)
            ->click('button[type="submit"]')
            ->waitUntilMissing('input[name="password"]', 15);
    }

    /**
     * A real, NON-BLANK 10x10 PNG. The canvas decoder validates actual image
     * bytes and rejects an empty canvas, so a transparent 1x1 is refused.
     */
    private function signaturePng(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
    }

    private function purgeFixtures(): void
    {
        $users = User::where('email', 'like', 'dusk-preconsent-rm1%')->pluck('id');
        $patients = Patient::where('name', 'like', self::MARKER.'%')->pluck('id');

        $visitIds = ClinicVisit::whereIn('patient_id', $patients)->pluck('id');
        $recordIds = MedicalRecord::whereIn('clinic_visit_id', $visitIds)->pluck('id');

        MedicalRecordHandwriting::whereIn('medical_record_id', $recordIds)->forceDelete();
        RmeVisitConsent::whereIn('clinic_visit_id', $visitIds)->forceDelete();
        Odontogram::whereIn('clinic_visit_id', $visitIds)->forceDelete();
        MedicalRecord::whereIn('id', $recordIds)->forceDelete();
        ClinicVisit::whereIn('id', $visitIds)->forceDelete();
        Patient::whereIn('id', $patients)->forceDelete();
        Doctor::whereIn('user_id', $users)->forceDelete();
        User::whereIn('id', $users)->forceDelete();
    }
}
