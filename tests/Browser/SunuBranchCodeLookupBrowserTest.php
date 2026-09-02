<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1 — the old card, in a real browser.
 *
 * The claim this sprint has to make to an operator is small and very concrete:
 * a patient whose stored Nomor RM was migrated to `DG-SPN4-…` is still found
 * when staff type the `DG-SUN4-…` printed on the card in the patient's wallet.
 *
 * A feature test can assert that a repository returns a row. It cannot assert
 * that the person at the front desk SEES the patient, and that is exactly the
 * gap a branch-code revision falls into: every server-side test passes while
 * the screen says "not found" and the clinic registers the same patient twice.
 *
 * Nothing is stubbed. Real Chrome, the real route, the real Blade template, and
 * a real PostgreSQL 16 database — the production driver.
 */
class SunuBranchCodeLookupBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    private const PROBE_NAME = 'Pasien Sunu Kartu Lama';

    private const OPERATOR_EMAIL = 'dusk-sunu-lookup@example.test';

    private const OPERATOR_PASSWORD = 'password';

    private const PROBE_PHONE = '081277700123';

    private const PROBE_KTP = '7371019999000789';

    /** The number actually stored, after the branch-code migration. */
    private const CANONICAL_RM = 'DG-SPN4-2024-7799';

    /** The number printed on the card the patient still carries. */
    private const OLD_CARD_RM = 'DG-SUN4-2024-7799';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_the_old_card_number_still_finds_the_migrated_sunu_patient(): void
    {
        $branch = $this->sunuBranch();
        $this->seedProbePatient($branch);
        $this->operator();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                ->assertSee('Belum ada pasien dipilih')
                // The DEPRECATED spelling — what is printed on the card.
                ->type('rm', self::OLD_CARD_RM)
                ->press('Cari Pasien')
                ->waitForText('Pasien ditemukan', 15);

            $browser->assertSee('Pasien ditemukan')
                ->assertSee(self::PROBE_NAME)
                // The CANONICAL number is what the screen reports back, so the
                // operator is told the current identifier rather than being
                // silently confirmed in the old one.
                ->assertSee(self::CANONICAL_RM)
                // The branch is DERIVED from the Nomor RM, never chosen — and it
                // derives to Cabang Sunu even though the typed code was SUN4.
                ->assertSee('Cabang Sunu')
                ->assertDontSee('Belum ada pasien dipilih')
                ->assertDontSee('Pasien tidak ditemukan')
                ->assertDontSee('Gagal mengambil data pasien');

            // Least disclosure still holds on this screen.
            $source = $browser->driver->getPageSource();
            $this->assertStringNotContainsString(self::PROBE_PHONE, $source);
            $this->assertStringNotContainsString(self::PROBE_KTP, $source);

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('sunu-old-card-found');
        });
    }

    public function test_the_canonical_number_finds_the_same_patient(): void
    {
        $branch = $this->sunuBranch();
        $this->seedProbePatient($branch);
        $this->operator();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                ->type('rm', self::CANONICAL_RM)
                ->press('Cari Pasien')
                ->waitForText('Pasien ditemukan', 15);

            $browser->assertSee(self::PROBE_NAME)
                ->assertSee('Cabang Sunu');

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('sunu-canonical-found');
        });
    }

    public function test_a_neighbouring_branch_code_is_not_widened_into_sunu(): void
    {
        $branch = $this->sunuBranch();
        $this->seedProbePatient($branch);
        $this->operator();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $this->loginAsOperator($browser);
            $this->drainConsole($browser);

            $browser->visit(route('settings.rme.legacy-odontograms.create'))
                ->waitForText('Unggah Arsip Odontogram Lama', 15)
                // SUN5 is not SUN4 and is not SPN4. Aliasing widens nothing.
                ->type('rm', 'DG-SUN5-2024-7799')
                ->press('Cari Pasien')
                ->waitForText('Pasien tidak ditemukan', 15);

            $browser->assertSee('Pasien tidak ditemukan')
                ->assertDontSee(self::PROBE_NAME)
                ->assertDontSee('Belum ada pasien dipilih')
                ->assertDontSee('Gagal mengambil data pasien');

            $this->assertRmePageHealthy($browser);
            $this->assertNoSevereConsoleErrors($browser);
            $browser->screenshot('sunu-neighbour-not-found');
        });
    }

    /** Cabang Sunu, resolved by its canonical code. */
    private function sunuBranch(): Branch
    {
        $branch = Branch::query()
            ->where('code', BranchCodeAlias::SUNU_CANONICAL)
            ->first();

        $this->assertNotNull($branch, 'Cabang Sunu must be seeded under its canonical code SPN4');
        $this->assertSame('Cabang Sunu', (string) $branch->name);

        return $branch;
    }

    private function seedProbePatient(Branch $branch): Patient
    {
        Patient::withTrashed()
            ->whereIn('medical_record_number', [self::CANONICAL_RM, self::OLD_CARD_RM])
            ->forceDelete();

        return Patient::factory()->create([
            'name' => self::PROBE_NAME,
            // Stored in the MIGRATED, canonical form.
            'medical_record_number' => self::CANONICAL_RM,
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
            'name' => 'Dusk Sunu Lookup Operator',
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
     * post-login landing legitimately answers 403 and Chrome logs it as SEVERE.
     * Draining here keeps the later assertion strict instead of tempting a
     * blanket filter that would also hide a real error on the page under test.
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
        Patient::withTrashed()
            ->whereIn('medical_record_number', [self::CANONICAL_RM, self::OLD_CARD_RM])
            ->forceDelete();

        $this->purgeOperator();
    }

    private function purgeOperator(): void
    {
        User::withTrashed()->where('email', self::OPERATOR_EMAIL)->forceDelete();
    }
}
