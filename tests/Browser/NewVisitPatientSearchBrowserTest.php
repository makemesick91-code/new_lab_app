<?php

namespace Tests\Browser;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Patient\Models\Patient;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1 — the proof that had been missing.
 *
 * Every other test of this control passed while production was returning HTTP
 * 500 for every keystroke, because none of them ran a real browser against a
 * real endpoint on a real PostgreSQL connection. This one does: Chrome types
 * into the combobox, the page's own Alpine component issues its own fetch, and
 * the assertions are made on what the operator would actually see.
 *
 * Nothing here is stubbed. There is no injected fetch, no fake response and no
 * mocked repository — a mock would have been green throughout the outage, which
 * is precisely the point.
 */
class NewVisitPatientSearchBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    /** Distinctive enough that no seeded record can satisfy the assertions. */
    private const PROBE_NAME = 'Zulkarnain Probe Dusk';

    private const PROBE_RM = 'DG-DUSK-2026-9101';

    protected function tearDown(): void
    {
        Patient::withTrashed()->where('medical_record_number', self::PROBE_RM)->forceDelete();

        parent::tearDown();
    }

    public function test_typing_a_name_returns_results_instead_of_the_error_state(): void
    {
        $this->seedProbePatient();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);

            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', 'Zulkarnain')
                // Longer than the 300ms debounce plus a real round trip.
                ->pause(2500);

            // The failure mode this sprint exists for.
            $browser->assertDontSee('Gagal mencari pasien');

            $browser->waitForText(self::PROBE_NAME, 10)
                ->assertSee(self::PROBE_NAME)
                ->assertSee(self::PROBE_RM);

            $this->assertRmePageHealthy($browser);
            $browser->screenshot('nvps-runtime-name-search');
        });
    }

    public function test_typing_a_nomor_rm_returns_the_patient(): void
    {
        $this->seedProbePatient();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);

            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', '2026-9101')
                ->pause(2500);

            $browser->assertDontSee('Gagal mencari pasien')
                ->waitForText(self::PROBE_NAME, 10)
                ->assertSee(self::PROBE_NAME);

            $this->assertRmePageHealthy($browser);
        });
    }

    public function test_a_query_matching_nobody_shows_the_empty_state_not_the_error_state(): void
    {
        // The distinction production lost: an honest "nothing matched" must not
        // be dressed as a transport failure.
        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);

            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', 'Qqzzxwv')
                ->pause(2500);

            $browser->assertDontSee('Gagal mencari pasien')
                ->assertSee('Tidak ada pasien yang sesuai.');

            $this->assertRmePageHealthy($browser);
            $browser->screenshot('nvps-runtime-empty-state');
        });
    }

    public function test_selecting_a_patient_sets_the_hidden_patient_id(): void
    {
        $patient = $this->seedProbePatient();

        $this->browse(function (Browser $browser) use ($patient) {
            $this->freshSession($browser);

            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', 'Zulkarnain')
                ->pause(2500)
                ->waitForText(self::PROBE_NAME, 10)
                ->click('[role="option"]')
                ->pause(500);

            $browser->assertValue('@patient-select', (string) $patient->id);
            $this->assertRmePageHealthy($browser);
        });
    }

    /**
     * Dusk reuses one Chrome profile across the tests in a class, so a session
     * left behind by an earlier test makes /login redirect straight to the
     * dashboard — and the login helper then looks for an email field that is no
     * longer on the page. Each test starts from no cookies at all.
     */
    private function freshSession(Browser $browser): void
    {
        $browser->visit('/login');
        $browser->driver->manage()->deleteAllCookies();
    }

    /** A patient the logged-in admin is genuinely authorized to find. */
    private function seedProbePatient(): Patient
    {
        Patient::withTrashed()->where('medical_record_number', self::PROBE_RM)->forceDelete();

        $branchId = app(BranchService::class)->rmeEnabledIds()[0] ?? null;

        $this->assertNotNull($branchId, 'no RME-enabled branch to attach the probe patient to');

        return Patient::factory()->create([
            'name' => self::PROBE_NAME,
            'medical_record_number' => self::PROBE_RM,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }
}
