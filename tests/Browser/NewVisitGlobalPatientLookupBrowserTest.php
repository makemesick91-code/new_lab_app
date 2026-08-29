<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — the proof in a real browser.
 *
 * A feature test can assert that the JSON contains a cross-branch patient. It
 * cannot assert that the operator SEES one: that requires the page's own Alpine
 * component to issue its own fetch against the real endpoint and paint the real
 * dropdown. The predecessor sprint learned this the expensive way — every
 * server-side test was green while every keystroke in production returned 500 —
 * so the cross-branch claim is made here too, through Chrome.
 *
 * The operator is a genuinely context-bound Admin Klinik pinned to ONE working
 * branch, which is the role the old rule restricted. A Super Admin would prove
 * nothing: it already had estate-wide scope before this sprint.
 *
 * Nothing is stubbed. No injected fetch, no fake response, no mocked repository.
 */
class NewVisitGlobalPatientLookupBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    /** Distinctive enough that no seeded record can satisfy the assertions. */
    private const PROBE_NAME = 'Jefri Lintas Cabang Dusk';

    private const PROBE_RM = 'DG-DUSK-2026-7788';

    private const OPERATOR_EMAIL = 'dusk-global-lookup@example.test';

    private const OPERATOR_PASSWORD = 'password';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_an_operator_at_one_branch_finds_a_patient_of_another_branch(): void
    {
        [$workingBranch, $originBranch] = $this->twoDistinctRmeBranches();

        $operator = $this->operatorWorkingAt($workingBranch);
        $probe = $this->seedProbePatientAt($originBranch);

        // The premise of the whole sprint: the operator is pinned to a branch
        // that is NOT the patient's, and is not a governance role.
        $this->assertSame(
            $workingBranch->id,
            app(RmeWorkingBranchScope::class)->activeBranchId($operator->fresh()),
            'the browser operator must be context-bound to the working branch'
        );
        $this->assertNotSame($workingBranch->id, $probe->branch_id);

        $this->browse(function (Browser $browser) use ($originBranch) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', 'Jefri Lintas')
                // Longer than the 300ms debounce plus a real round trip.
                ->pause(2500);

            $browser->assertDontSee('Gagal mencari pasien')
                ->assertDontSee('Tidak ada pasien yang sesuai.');

            $browser->waitForText(self::PROBE_NAME, 10)
                ->assertSee(self::PROBE_NAME)
                ->assertSee(self::PROBE_RM)
                // The origin branch is printed as a disambiguating label. It is
                // NOT the branch the visit will be created at.
                ->assertSee((string) $originBranch->name);

            // Least disclosure survives the wider scope, in the rendered DOM.
            $source = $browser->driver->getPageSource();
            foreach (['081277700088', '7371019999000123'] as $secret) {
                $this->assertStringNotContainsString($secret, $source);
            }

            $this->assertRmePageHealthy($browser);
            $browser->screenshot('nvgpl-cross-branch-result');
        });
    }

    public function test_selecting_the_cross_branch_patient_sets_the_hidden_patient_id(): void
    {
        [$workingBranch, $originBranch] = $this->twoDistinctRmeBranches();

        $this->operatorWorkingAt($workingBranch);
        $probe = $this->seedProbePatientAt($originBranch);

        $this->browse(function (Browser $browser) use ($probe) {
            $this->freshSession($browser);

            $this->loginAsOperator($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 15)
                ->click('@patient-search')
                ->type('@patient-search', 'Jefri Lintas')
                ->pause(2500)
                ->waitForText(self::PROBE_NAME, 10)
                ->click('[role="option"]')
                ->pause(500);

            // The selection is real: the hidden field the form actually submits
            // now carries the OTHER branch's patient id.
            $browser->assertValue('@patient-select', (string) $probe->id);

            $this->assertRmePageHealthy($browser);
            $browser->screenshot('nvgpl-cross-branch-selected');
        });
    }

    /**
     * @return array{0: Branch, 1: Branch}
     */
    private function twoDistinctRmeBranches(): array
    {
        $ids = app(BranchService::class)->rmeEnabledIds();

        $this->assertGreaterThanOrEqual(2, count($ids), 'the cross-branch claim needs two RME branches');

        return [
            Branch::query()->findOrFail($ids[0]),
            Branch::query()->findOrFail($ids[1]),
        ];
    }

    /** A context-bound Admin Klinik, pinned to one working branch. */
    private function operatorWorkingAt(Branch $branch): User
    {
        $this->purgeOperator();

        $user = User::factory()->create([
            'name' => 'Dusk Global Lookup Operator',
            'email' => self::OPERATOR_EMAIL,
            'password' => Hash::make(self::OPERATOR_PASSWORD),
        ]);

        $user->assignRole('Admin Klinik');
        $user->givePermissionTo('manage_clinic_visits');
        $user->givePermissionTo('view_clinic_visits');

        app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $branch->id);

        return $user->fresh();
    }

    private function seedProbePatientAt(Branch $branch): Patient
    {
        Patient::withTrashed()->where('medical_record_number', self::PROBE_RM)->forceDelete();

        return Patient::factory()->create([
            'name' => self::PROBE_NAME,
            'medical_record_number' => self::PROBE_RM,
            'branch_id' => $branch->id,
            'is_active' => true,
            // Deliberately populated so the DOM assertion above is meaningful.
            'phone' => '081277700088',
            'ktp_number' => '7371019999000123',
        ]);
    }

    /**
     * Dusk reuses one Chrome profile across the tests in a class, so a session
     * left behind by an earlier test makes /login redirect straight past the
     * form. Each test starts from no cookies at all.
     */
    private function freshSession(Browser $browser): void
    {
        $browser->visit('/login');
        $browser->driver->manage()->deleteAllCookies();
    }

    /**
     * Admin Klinik does not land on the generic dashboard, so this waits for the
     * login form to be gone rather than for a role-specific heading.
     */
    private function loginAsOperator(Browser $browser): Browser
    {
        return $browser->visit('/login')
            ->type('email', self::OPERATOR_EMAIL)
            ->type('password', self::OPERATOR_PASSWORD)
            ->click('button[type="submit"]')
            ->waitUntilMissing('input[name="password"]', 15);
    }

    private function purgeFixtures(): void
    {
        Patient::withTrashed()->where('medical_record_number', self::PROBE_RM)->forceDelete();
        $this->purgeOperator();
    }

    private function purgeOperator(): void
    {
        User::withTrashed()->where('email', self::OPERATOR_EMAIL)->each(function (User $user): void {
            $user->forceDelete();
        });
    }
}
