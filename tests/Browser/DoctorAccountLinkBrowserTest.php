<?php

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1 — the proof in a real browser.
 *
 * A feature test can assert that a controller persisted `user_id`. It cannot
 * assert that an administrator can actually *perform* the link from the Master
 * Data screen, or that the doctor whose account was just linked really sees
 * their own kinerja and pendapatan afterwards. The whole point of this sprint
 * was that the relation existed but no human could reach it, so the claim is
 * made here — through Chrome, against the real routes, the real Blade templates
 * and a real PostgreSQL 16 database, the production driver.
 *
 * Nothing about the subject is stubbed: the link is created by clicking the
 * real form. The doctor's online-context row is created server-side because
 * choosing a branch and room is a different, already-proven flow (Sprint 66.0)
 * and is not what this sprint changed.
 *
 * Every fixture is namespaced by the markers below and removed in tearDown.
 */
class DoctorAccountLinkBrowserTest extends DuskTestCase
{
    private const MANAGER_EMAIL = 'dusk-dal-manager@example.test';

    private const DOCTOR_EMAIL = 'dusk-dal-doctor@example.test';

    private const PASSWORD = 'password';

    private const DOCTOR_NAME = 'Dr. Dusk Terhubung';

    private const OTHER_DOCTOR_NAME = 'Dr. Dusk Orang Lain';

    private const DOCTOR_CODE = 'DUSK-DAL-1';

    private const OTHER_DOCTOR_CODE = 'DUSK-DAL-2';

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_an_administrator_links_an_account_and_the_doctor_then_sees_only_their_own_income(): void
    {
        $this->purgeFixtures();

        $branch = $this->rmeBranch();
        $manager = $this->manager();
        $doctorUser = $this->doctorAccount();

        $doctor = Doctor::create([
            'clinic_id' => null,
            'branch_id' => $branch->id,
            'user_id' => null,
            'code' => self::DOCTOR_CODE,
            'name' => self::DOCTOR_NAME,
            'is_active' => true,
        ]);
        $doctor->branches()->sync([$branch->id]);

        $other = Doctor::create([
            'clinic_id' => null,
            'branch_id' => $branch->id,
            'user_id' => null,
            'code' => self::OTHER_DOCTOR_CODE,
            'name' => self::OTHER_DOCTOR_NAME,
            'is_active' => true,
        ]);
        $other->branches()->sync([$branch->id]);

        // 1. The administrator performs the link from Master Data, in the browser.
        $this->browse(function (Browser $browser) use ($manager, $doctor, $doctorUser) {
            $this->login($browser, $manager->email)
                ->visit('/settings/doctors/account-links')
                ->waitForText('Relasi Akun Dokter', 15)
                ->assertSee(self::DOCTOR_NAME)
                ->assertSee('Belum Terhubung');

            $this->assertPageHealthy($browser);

            $form = '[data-link-form="'.$doctor->id.'"]';

            $browser->select($form.' select[name="user_id"]', (string) $doctorUser->id)
                ->press($form.' button[type="submit"]')
                ->waitForText('berhasil dihubungkan', 15)
                ->assertSee('Terhubung')
                ->assertSee($doctorUser->email);
        });

        $this->assertSame(
            $doctorUser->id,
            (int) $doctor->fresh()->user_id,
            'The link made in the browser must be the persisted one.'
        );

        // 2. The linked doctor now reaches their own kinerja + pendapatan, and a
        //    forged doctor_id for a colleague changes nothing.
        app(UserOnlineContextService::class)->startDoctorSession(
            $doctorUser->fresh(),
            (int) $branch->id,
            (int) $this->room($branch)->id,
        );

        $this->browse(function (Browser $browser) use ($doctorUser, $other) {
            $this->login($browser, $doctorUser->email)
                ->visit('/rme/reports/doctor-performance')
                ->waitForText('Dokter', 15)
                ->assertSee(self::DOCTOR_NAME)
                ->assertDontSee(self::OTHER_DOCTOR_NAME);

            $this->assertPageHealthy($browser);

            // IDOR: the request asks for a colleague; the server ignores it.
            $browser->visit('/rme/reports/doctor-performance?doctor_id='.$other->id)
                ->waitForText('Dokter', 15)
                ->assertSee(self::DOCTOR_NAME)
                ->assertDontSee(self::OTHER_DOCTOR_NAME);

            $this->assertPageHealthy($browser);
        });

        // 3. Unlinking withdraws that access immediately, in the browser.
        $this->browse(function (Browser $browser) use ($manager, $doctor) {
            $this->login($browser, $manager->email)
                ->visit('/settings/doctors/account-links')
                ->waitForText('Relasi Akun Dokter', 15);

            $browser->press('[data-unlink-form="'.$doctor->id.'"] button[type="submit"]')
                ->acceptDialog()
                ->waitForText('telah diputus', 15)
                ->assertSee('Belum Terhubung');
        });

        $this->assertNull($doctor->fresh()->user_id);
    }

    public function test_an_operator_without_the_linkage_permission_cannot_reach_the_page(): void
    {
        $this->purgeFixtures();
        $this->rmeBranch();

        // Holds `manage doctors` (ordinary doctor master-data maintenance) but
        // not `manage_doctor_account_links`.
        $limited = $this->manager(withLinkPermission: false);

        $this->browse(function (Browser $browser) use ($limited) {
            $this->login($browser, $limited->email)
                ->visit('/settings/doctors/account-links')
                ->pause(2000);

            $source = $browser->driver->getPageSource();

            $this->assertStringNotContainsString(
                'Relasi Akun Dokter',
                $source,
                'A user without the linkage permission must never receive the management page.'
            );
            $this->assertMatchesRegularExpression(
                '/403|Forbidden|Unauthorized|Halaman tidak/i',
                $source,
                'The route must refuse, not render.'
            );
        });
    }

    /* --------------------------------------------------------------------- */

    private function login(Browser $browser, string $email): Browser
    {
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/login')
            ->waitFor('input[name="email"]', 15)
            ->type('email', $email)
            ->type('password', self::PASSWORD)
            ->press('button[type="submit"]')
            ->pause(1500);
    }

    private function assertPageHealthy(Browser $browser): void
    {
        foreach (['Server Error', 'SQLSTATE', 'Whoops', '500 | Server Error'] as $text) {
            $browser->assertDontSee($text);
        }
    }

    private function rmeBranch(): Branch
    {
        $ids = app(BranchService::class)->rmeEnabledIds();

        $branch = $ids === [] ? null : Branch::query()->whereKey($ids[0])->first();

        if ($branch === null) {
            $this->markTestSkipped('No RME-enabled branch available in this environment.');
        }

        return $branch;
    }

    private function room(Branch $branch): ClinicRoom
    {
        return ClinicRoom::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'DUSK-DAL-R1'],
            ['name' => 'Ruang Dusk Relasi', 'type' => ClinicRoom::TYPE_TREATMENT_ROOM, 'status' => 'active'],
        );
    }

    private function manager(bool $withLinkPermission = true): User
    {
        $email = $withLinkPermission ? self::MANAGER_EMAIL : 'dusk-dal-limited@example.test';

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->deleted_at = null;
        $user->name = 'Dusk Relasi Manager';
        $user->password = Hash::make(self::PASSWORD);
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->syncRoles([]);
        $user->syncPermissions(array_filter([
            'view dashboard',
            'manage doctors',
            $withLinkPermission ? 'manage_doctor_account_links' : null,
        ]));

        return $user->fresh();
    }

    private function doctorAccount(): User
    {
        $user = User::withTrashed()->firstOrNew(['email' => self::DOCTOR_EMAIL]);
        $user->deleted_at = null;
        $user->name = 'Akun Dusk Dokter';
        $user->password = Hash::make(self::PASSWORD);
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->syncRoles(['Doctor']);

        return $user->fresh();
    }

    private function purgeFixtures(): void
    {
        $doctorIds = Doctor::withTrashed()
            ->whereIn('code', [self::DOCTOR_CODE, self::OTHER_DOCTOR_CODE])
            ->pluck('id');

        if ($doctorIds->isNotEmpty()) {
            DB::table('mst_doctor_branches')->whereIn('doctor_id', $doctorIds)->delete();
            DB::table('sys_audit_logs')
                ->where('entity_type', Doctor::class)
                ->whereIn('entity_id', $doctorIds)
                ->delete();
            Doctor::withTrashed()->whereIn('id', $doctorIds)->forceDelete();
        }

        $userIds = User::withTrashed()
            ->whereIn('email', [self::MANAGER_EMAIL, self::DOCTOR_EMAIL, 'dusk-dal-limited@example.test'])
            ->pluck('id');

        if ($userIds->isNotEmpty()) {
            DB::table('trx_user_online_contexts')->whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)
                ->where('model_type', User::class)->delete();
            DB::table('model_has_permissions')->whereIn('model_id', $userIds)
                ->where('model_type', User::class)->delete();
            // force: `users` soft-deletes, and the unique email index still sees
            // a trashed row — a plain delete would break the next run.
            User::withTrashed()->whereIn('id', $userIds)->forceDelete();
        }

        ClinicRoom::query()->where('code', 'DUSK-DAL-R1')->forceDelete();
    }
}
