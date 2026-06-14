<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeIndexSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_visits_index_loads_without_errors(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.index'))
                ->waitForText('Kunjungan Pasien', 10)
                ->assertSee('Kunjungan Pasien');

            $this->assertRmePageHealthy($browser);
            $this->assertRmeKeywordPresent($browser);

            $browser->screenshot('rme-index-smoke');
        });
    }
}
