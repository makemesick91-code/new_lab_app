<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 — the proof in a real browser.
 *
 * The feature suite proves the SERVER accepts a pre-consent chart. It cannot
 * prove the doctor is offered a way to enter one, and the whole point of this
 * revision is that a doctor who has started an examination can actually chart.
 * The gap between "the service would have allowed it" and "the screen let them"
 * is where the old rule hurt, so the claim is made here through Chrome, against
 * the real routes, the real Blade template and a real PostgreSQL 16 database —
 * the production driver.
 *
 * Nothing is stubbed. The save is a real form submission; the persistence check
 * is a real page reload. The consent is signed through
 * RmeVisitConsentService::sign() rather than by drawing on the canvas, which is
 * the fixture path the sprint brief explicitly permits — the signature CANVAS is
 * not what this test is about, and the service is the real signing path.
 *
 * Every fixture is namespaced by MARKER and removed in tearDown, so the shared
 * database is left exactly as it was found.
 */
class OdontogramPreConsentEditBrowserTest extends DuskTestCase
{
    private const MARKER = 'DUSK-PRECONSENT-ODO';

    private const OPERATOR_EMAIL = 'dusk-preconsent-odo@example.test';

    private const OPERATOR_PASSWORD = 'password';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_the_doctor_charts_before_consent_and_the_record_and_finish_stay_locked(): void
    {
        [$user, $visit] = $this->makeLiveEncounter();

        $this->browse(function (Browser $browser) use ($user, $visit) {
            $this->login($browser, $user);

            // 1. The chart is offered while the consent is still unsigned.
            $browser->visit(route('rme.visits.odontogram.show', $visit, false))
                ->assertSee('Odontogram')
                ->assertSee('Persetujuan Tindakan Medis belum ditandatangani')
                ->assertSee('Simpan Odontogram')
                ->assertDontSee('Server Error');

            // 2. Chart one tooth through the real editor and save. Driven by the
            //    production DOM — no dusk="" hooks were added to the template
            //    just to make this test convenient.
            $browser->select('select[x-model="newTooth"]', '11')
                ->select('select[x-model="newStatus"]', 'caries')
                ->press('+ Tambah Baris')
                ->waitForText('11')
                ->press('Simpan Odontogram')
                ->waitForLocation(route('rme.visits.odontogram.show', $visit, false));

            // 3. It really persisted — reload and read it back from the server.
            $browser->visit(route('rme.visits.odontogram.show', $visit, false))
                ->assertSee('Karies');

            $this->assertSame(
                'caries',
                Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail()
                    ->tooth_map_payload['teeth']['11']['status'] ?? null,
                'the pre-consent chart must be persisted server-side',
            );

            // 4. The RME is STILL refused.
            //
            //    SUPERSEDED BY BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1. This
            //    step used to PRESS "Buat Halaman RM Pertama" and watch the
            //    server refuse, with a note recording that the button was gated
            //    on the manage permission only and was therefore offered against
            //    an act the server would reject. That note described a bug, and
            //    the bug is now fixed: the control is rendered disabled, so
            //    there is nothing left to press here.
            //
            //    The claim this step makes for THIS sprint is unchanged and is
            //    still the sharp one — blocking the record did not travel with
            //    the odontogram permission. It is now made by reading the state
            //    rather than by provoking a failure.
            $browser->visit(route('rme.visits.medical-record.show', $visit, false))
                ->assertDontSee('Server Error')
                ->assertSee('Belum ada halaman RM tulisan tangan')
                ->assertSee('Persetujuan Tindakan Medis belum ditandatangani')
                ->assertPresent('button[disabled]');

            $this->assertStringNotContainsString(
                'action="'.route('rme.visits.medical-record.store', $visit, false).'"',
                $browser->driver->getPageSource(),
                'the empty state must offer no submittable create form before consent',
            );

            $this->assertSame(
                0,
                MedicalRecord::where('patient_id', $visit->patient_id)->count(),
                'no medical record may be created while the consent is unsigned',
            );

            // 5. Finishing the examination is still refused.
            $browser->visit(route('rme.visits.show', $visit, false))
                ->assertDontSee('Server Error');

            $this->assertSame(
                ClinicVisit::STATUS_IN_PROGRESS,
                $visit->fresh()->status,
                'charting must never advance the visit',
            );
            $this->assertFalse(
                app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()),
                'charting must never create or sign a consent',
            );

            // 6. Sign the consent through the real service, then reload.
            app(RmeVisitConsentService::class)->sign($visit->fresh(), $user, [
                'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
                'consenter_relationship' => 'self',
                'medical_action' => 'Pencabutan gigi 36 ('.self::MARKER.')',
                'treatment_summary' => self::MARKER,
                'documentation_consent' => false,
                'consenter_signature' => $this->signaturePng(),
            ]);

            // 7. The chart survived the signature, and the reminder is gone.
            $browser->visit(route('rme.visits.odontogram.show', $visit, false))
                ->assertSee('Karies')
                ->assertSee('Simpan Odontogram')
                ->assertDontSee('Persetujuan Tindakan Medis belum ditandatangani');

            $this->assertSame(
                'caries',
                Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail()
                    ->tooth_map_payload['teeth']['11']['status'] ?? null,
                'signing the consent must not disturb the pre-consent chart',
            );
        });
    }

    public function test_a_doctor_cannot_open_the_write_surface_of_another_patients_encounter(): void
    {
        [, $visit] = $this->makeLiveEncounter();
        $otherUser = $this->makeScopedOtherDoctor();

        $this->browse(function (Browser $browser) use ($otherUser, $visit) {
            $this->login($browser, $otherUser);

            $browser->visit(route('rme.visits.odontogram.show', $visit, false))
                ->assertDontSee('Simpan Odontogram');
        });

        $this->assertNull(
            Odontogram::where('clinic_visit_id', $visit->id)->first()?->tooth_map_payload,
            'the other doctor must not have charted anything',
        );
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

    private function makeScopedOtherDoctor(): User
    {
        $branch = Branch::where('is_active', true)->where('is_rme_enabled', true)->firstOrFail();

        $user = User::factory()->create([
            'name' => self::MARKER.' Dokter Lain',
            'email' => 'dusk-preconsent-odo-other@example.test',
            'password' => Hash::make(self::OPERATOR_PASSWORD),
            'branch_id' => $branch->id,
        ]);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);
        $user->assignRole('Doctor');

        Doctor::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => self::MARKER.' Dokter Lain',
        ]);

        return $user;
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
     * A real, NON-BLANK 10x10 PNG. PrescriptionCanvasDecoder validates actual
     * image bytes and rejects an empty canvas, so a transparent 1x1 is refused —
     * which is the decoder doing its job. Same payload the Pest suite uses.
     */
    private function signaturePng(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
    }

    private function purgeFixtures(): void
    {
        $users = User::where('email', 'like', 'dusk-preconsent-odo%')->pluck('id');
        $patients = Patient::where('name', 'like', self::MARKER.'%')->pluck('id');

        $visitIds = ClinicVisit::whereIn('patient_id', $patients)->pluck('id');

        RmeVisitConsent::whereIn('clinic_visit_id', $visitIds)->forceDelete();
        Odontogram::whereIn('clinic_visit_id', $visitIds)->forceDelete();
        MedicalRecord::whereIn('clinic_visit_id', $visitIds)->forceDelete();
        ClinicVisit::whereIn('id', $visitIds)->forceDelete();
        Patient::whereIn('id', $patients)->forceDelete();
        Doctor::whereIn('user_id', $users)->forceDelete();
        User::whereIn('id', $users)->forceDelete();
    }
}
