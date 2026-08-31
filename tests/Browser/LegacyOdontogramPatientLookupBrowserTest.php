<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the proof in a real browser.
 *
 * The reported defect was "data pasien tidak muncul setelah identifier pasien
 * dimasukkan". A feature test can assert that a controller returns a patient;
 * it cannot assert that an operator SEES one. The whole defect lived in the gap
 * between "the server resolved something" and "the screen said so", so the
 * claim is made here, through Chrome, against the real route, the real Blade
 * template and a real PostgreSQL 16 database — the production driver.
 *
 * Nothing is stubbed. No injected fetch, no fake response, no mocked
 * repository. The runtime-error case is produced by genuinely renaming
 * `mst_patients` out from under one page load, so the failure the operator sees
 * is a real PostgreSQL error surfacing through the real driver rather than a
 * simulated one. The rename is reversed immediately afterwards and costs no
 * data and no constraint.
 */
class LegacyOdontogramPatientLookupBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    private const PROBE_NAME = 'Pasien Arsip Dusk Odontogram';

    private const OPERATOR_EMAIL = 'dusk-lodo-lookup@example.test';

    private const OPERATOR_PASSWORD = 'password';

    /** Populated so the least-disclosure assertion is meaningful. */
    private const PROBE_PHONE = '081277700099';

    private const PROBE_KTP = '7371019999000456';

    /** Where `mst_patients` is parked while the failure is provoked. */
    private const OFFLINE_TABLE = 'mst_patients_dusk_offline';

    protected function tearDown(): void
    {
        $this->restorePatientTableAccess();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_the_operator_finds_the_patient_by_the_nomor_rm_they_actually_hold(): void
    {
        $branch = $this->rmeBranch();
        $probe = $this->seedProbePatient($branch);
        $this->operator();

        $this->browse(function (Browser $browser) use ($probe) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                // Before anything is typed there is exactly one blank state.
                ->assertSee('Belum ada pasien dipilih')
                ->type('rm', (string) $probe->medical_record_number)
                ->press('Cari Pasien')
                ->waitForText('Pasien ditemukan', 15);

            $browser->assertSee('Pasien ditemukan')
                ->assertSee(self::PROBE_NAME)
                ->assertSee((string) $probe->medical_record_number)
                // The branch is DERIVED from the Nomor RM, never chosen.
                ->assertSee('Diturunkan dari Nomor RM')
                ->assertDontSee('Belum ada pasien dipilih')
                ->assertDontSee('Pasien tidak ditemukan')
                ->assertDontSee('Gagal mengambil data pasien');

            // Least disclosure, asserted against the DOM the operator received.
            $source = $browser->driver->getPageSource();
            $this->assertStringNotContainsString(self::PROBE_PHONE, $source);
            $this->assertStringNotContainsString(self::PROBE_KTP, $source);

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('lodo-lookup-found');
        });
    }

    public function test_an_unknown_nomor_rm_is_reported_as_not_found(): void
    {
        $this->rmeBranch();
        $this->operator();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                ->type('rm', 'DG-TLK1-1999-0000')
                ->press('Cari Pasien')
                ->waitForText('Pasien tidak ditemukan', 15);

            $browser->assertSee('Pasien tidak ditemukan')
                // The distinction the defect erased: this is NOT the same panel
                // as "you have not typed anything yet".
                ->assertDontSee('Belum ada pasien dipilih')
                ->assertDontSee('Gagal mengambil data pasien');

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('lodo-lookup-not-found');
        });
    }

    public function test_a_real_lookup_failure_is_reported_as_a_runtime_error(): void
    {
        $branch = $this->rmeBranch();
        $probe = $this->seedProbePatient($branch);
        $this->operator();

        $rm = (string) $probe->medical_record_number;

        $this->browse(function (Browser $browser) use ($rm) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15);

            // A GENUINE failure: the table is renamed away, so the very next
            // lookup query really does fail. Nothing is stubbed, and nothing is
            // injected into the page.
            $this->revokePatientTableAccess();

            $browser->type('rm', $rm)
                ->press('Cari Pasien')
                ->waitForText('Gagal mengambil data pasien', 15);

            $browser->assertSee('Gagal mengambil data pasien. Silakan coba lagi.')
                // A database fault must never be reported as "this patient does
                // not exist" — that is how a real patient gets registered twice.
                ->assertDontSee('Pasien tidak ditemukan')
                ->assertDontSee('Belum ada pasien dipilih')
                // And it must never become a white 500 on a migration screen.
                ->assertDontSee('Server Error')
                ->assertDontSee('SQLSTATE');

            $this->restorePatientTableAccess();

            $browser->screenshot('lodo-lookup-runtime-error');
        });
    }

    public function test_clearing_the_identifier_clears_the_patient_panel(): void
    {
        $branch = $this->rmeBranch();
        $probe = $this->seedProbePatient($branch);
        $this->operator();

        $this->browse(function (Browser $browser) use ($probe) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                ->type('rm', (string) $probe->medical_record_number)
                ->press('Cari Pasien')
                ->waitForText('Pasien ditemukan', 15)
                ->assertSee(self::PROBE_NAME);

            // Emptying the field and searching again must not leave the previous
            // patient on screen.
            $browser->clear('rm')
                ->press('Cari Pasien')
                ->waitForText('Belum ada pasien dipilih', 15);

            $browser->assertDontSee(self::PROBE_NAME)
                ->assertDontSee('Pasien ditemukan');

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('lodo-lookup-cleared');
        });
    }

    /**
     * An RME branch whose CODE the canonical Nomor RM parser can actually read.
     *
     * The RM format is DG-{CODE}-{YEAR}-{NNNN}, so a factory-generated code
     * containing its own dash splits into too many segments and the branch
     * derivation fails closed. Picking a parseable code means the FOUND state
     * also demonstrates the derived branch, rather than only the identity.
     */
    private function rmeBranch(): Branch
    {
        $branches = Branch::query()
            ->whereIn('id', app(BranchService::class)->rmeEnabledIds())
            ->get()
            ->filter(fn (Branch $branch): bool => ! str_contains((string) $branch->code, '-'));

        $this->assertNotEmpty($branches, 'the lookup needs an RME branch with a parseable code');

        return $branches->first();
    }

    private function seedProbePatient(Branch $branch): Patient
    {
        $rm = sprintf('DG-%s-2024-7799', $branch->code);

        Patient::withTrashed()->where('medical_record_number', $rm)->forceDelete();

        return Patient::factory()->create([
            'name' => self::PROBE_NAME,
            'medical_record_number' => $rm,
            'branch_id' => $branch->id,
            'is_active' => true,
            'phone' => self::PROBE_PHONE,
            'ktp_number' => self::PROBE_KTP,
        ]);
    }

    /** An operator holding the intake permission, never Super Admin. */
    private function operator(): User
    {
        $this->purgeOperator();

        $user = User::factory()->create([
            'name' => 'Dusk Legacy Odontogram Operator',
            'email' => self::OPERATOR_EMAIL,
            'password' => Hash::make(self::OPERATOR_PASSWORD),
        ]);

        $user->givePermissionTo('view_legacy_odontogram_imports');
        $user->givePermissionTo('create_legacy_odontogram_imports');

        return $user->fresh();
    }

    private function freshSession(Browser $browser): void
    {
        $browser->visit('/login');
        $browser->driver->manage()->deleteAllCookies();
    }

    private function loginAsOperator(Browser $browser): Browser
    {
        return $browser->visit('/login')
            ->type('email', self::OPERATOR_EMAIL)
            ->type('password', self::OPERATOR_PASSWORD)
            ->click('button[type="submit"]')
            ->waitUntilMissing('input[name="password"]', 15);
    }

    /**
     * Discard whatever the login landing produced before the page under test is
     * even open.
     *
     * This operator holds only the two legacy-odontogram permissions, so the
     * post-login landing legitimately answers 403 and Chrome records it as a
     * SEVERE network entry. That is the authorization working, not a defect —
     * but leaving it in the buffer would either fail every assertion below or,
     * worse, tempt a blanket filter that also hides a real error on the lookup
     * page. Draining here keeps the assertion that follows strict.
     */
    private function drainConsole(Browser $browser): void
    {
        $browser->driver->manage()->getLog('browser');
    }

    private function assertNoSevereConsoleErrors(Browser $browser): void
    {
        $severe = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => ($entry['level'] ?? '') === 'SEVERE')
            // A favicon 404 is not a page defect.
            ->reject(fn (array $entry): bool => str_contains((string) ($entry['message'] ?? ''), 'favicon'))
            ->values();

        $this->assertCount(0, $severe, 'unexpected SEVERE browser console entries: '.json_encode($severe->all()));
    }

    /** The table really does disappear; the query really does fail. */
    private function revokePatientTableAccess(): void
    {
        DB::statement('ALTER TABLE mst_patients RENAME TO '.self::OFFLINE_TABLE);
    }

    private function restorePatientTableAccess(): void
    {
        try {
            if (DB::selectOne('SELECT to_regclass(?) AS t', [self::OFFLINE_TABLE])?->t !== null) {
                DB::statement('ALTER TABLE '.self::OFFLINE_TABLE.' RENAME TO mst_patients');
            }
        } catch (\Throwable) {
            // Nothing was renamed, or the connection is gone — nothing to undo.
        }
    }

    private function purgeFixtures(): void
    {
        Patient::withTrashed()->where('name', self::PROBE_NAME)->forceDelete();
        $this->purgeOperator();
    }

    private function purgeOperator(): void
    {
        User::withTrashed()->where('email', self::OPERATOR_EMAIL)->each(function (User $user): void {
            $user->forceDelete();
        });
    }
}
