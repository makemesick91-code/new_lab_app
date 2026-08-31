<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1 — the proof in a real browser.
 *
 * What the operator was actually told, on the screen, was:
 *
 *     "Pasien belum memiliki odontogram di sistem, sehingga arsip lama belum
 *      dapat diarsipkan."
 *
 * A feature test can assert that the date rule now returns a pass; it cannot
 * assert that the operator is no longer told to give up. The defect was a
 * refusal an operator READ, so the claim is made here — through Chrome, against
 * the real route, the real Blade template and a real PostgreSQL database.
 *
 * Nothing is stubbed. The probe patient genuinely has no native odontogram: it
 * is created and never given a visit, which is exactly the state the archive
 * used to refuse.
 */
class LegacyOdontogramNativeOptionalBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    private const PROBE_NAME = 'Pasien Arsip Dusk Tanpa Odontogram';

    private const OPERATOR_EMAIL = 'dusk-lodo-native-optional@example.test';

    private const OPERATOR_PASSWORD = 'password';

    /** The refusal this revision removed. It must not appear anywhere. */
    private const RETIRED_REFUSAL = 'belum dapat diarsipkan';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_a_patient_with_no_native_odontogram_is_presented_as_archivable(): void
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
                ->waitForText('Pasien ditemukan', 15);

            $browser->assertSee(self::PROBE_NAME)
                // The cutoff panel still tells the truth: this patient has none…
                ->assertSee('Belum ada')
                // …and says so as guidance rather than as a refusal.
                ->assertSee('Arsip lama tetap dapat diunggah')
                ->assertDontSee(self::RETIRED_REFUSAL);

            $source = $browser->driver->getPageSource();

            /*
             * The panel's own label is asserted against the SOURCE, not the
             * rendered text: it carries Tailwind's `uppercase`, and Selenium's
             * getText() returns text with CSS text-transform already applied —
             * so `assertSee('Odontogram Pertama di Sistem')` compares against
             * "ODONTOGRAM PERTAMA DI SISTEM" and fails for a styling reason
             * that has nothing to do with this sprint.
             */
            $this->assertStringContainsString('Odontogram Pertama di Sistem', $source);

            // The retired refusal must be absent from the DOM the operator
            // received, not merely invisible — a hidden or collapsed copy fails.
            $this->assertStringNotContainsString(
                self::RETIRED_REFUSAL,
                $source,
                'the retired native-required refusal is still rendered to the operator',
            );

            // The upload controls are reachable rather than dead-ended.
            $browser->assertPresent('input[name="selected_odontogram_date"]')
                ->assertPresent('input[name="document"]');

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('lodo-native-optional-archivable');
        });
    }

    /**
     * An RME branch whose CODE the canonical Nomor RM parser can read.
     *
     * The RM format is DG-{CODE}-{YEAR}-{NNNN}, so a code containing its own
     * dash splits into too many segments and branch derivation fails closed.
     */
    private function rmeBranch(): Branch
    {
        $branches = Branch::query()
            ->whereIn('id', app(BranchService::class)->rmeEnabledIds())
            ->get()
            ->filter(fn (Branch $branch): bool => ! str_contains((string) $branch->code, '-'));

        $this->assertNotEmpty($branches, 'the archive needs an RME branch with a parseable code');

        return $branches->first();
    }

    /**
     * A patient with NO clinic visit and therefore NO native odontogram — the
     * exact state the archive used to refuse.
     */
    private function seedProbePatient(Branch $branch): Patient
    {
        $rm = sprintf('DG-%s-2024-7801', $branch->code);

        Patient::withTrashed()->where('medical_record_number', $rm)->forceDelete();

        return Patient::factory()->create([
            'name' => self::PROBE_NAME,
            'medical_record_number' => $rm,
            'branch_id' => $branch->id,
            'is_active' => true,
            'date_of_birth' => '1990-01-01',
        ]);
    }

    /** An operator holding the intake permission, never Super Admin. */
    private function operator(): User
    {
        $this->purgeOperator();

        $user = User::factory()->create([
            'name' => 'Dusk Legacy Odontogram Native Optional Operator',
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
     * This operator holds only the two legacy-odontogram permissions, so the
     * post-login landing legitimately answers 403 and Chrome logs it SEVERE.
     * Draining here keeps the later assertion strict rather than blanket-filtered.
     */
    private function drainConsole(Browser $browser): void
    {
        $browser->driver->manage()->getLog('browser');
    }

    private function assertNoSevereConsoleErrors(Browser $browser): void
    {
        $severe = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => ($entry['level'] ?? '') === 'SEVERE')
            ->reject(fn (array $entry): bool => str_contains((string) ($entry['message'] ?? ''), 'favicon'))
            ->values();

        $this->assertCount(0, $severe, 'unexpected SEVERE browser console entries: '.json_encode($severe->all()));
    }

    private function purgeFixtures(): void
    {
        Patient::withTrashed()->where('name', self::PROBE_NAME)->forceDelete();
        $this->purgeOperator();
    }

    private function purgeOperator(): void
    {
        User::withTrashed()->where('email', self::OPERATOR_EMAIL)->forceDelete();
    }
}
