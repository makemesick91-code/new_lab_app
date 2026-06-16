<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeCreateSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_visit_create_form_loads_without_errors(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.create'))
                ->waitForText('Daftar Kunjungan Baru', 10)
                ->assertSee('Daftar Kunjungan Baru')
                ->assertInputPresent('branch_id')
                ->assertPresent('select[name="branch_id"]')
                ->assertPresent('@patient-search')
                ->assertPresent('@patient-select');

            $this->assertRmePageHealthy($browser);

            $browser->screenshot('rme-create-smoke');
        });
    }
}
