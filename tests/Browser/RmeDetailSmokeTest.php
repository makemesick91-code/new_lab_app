<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeDetailSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_visit_detail_loads_without_errors(): void
    {
        $visit = $this->resolveClinicVisit();

        if (! $visit) {
            $this->markTestSkipped('No clinic visit found in database for RME detail smoke test.');
        }

        $this->browse(function (Browser $browser) use ($visit) {
            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.show', $visit))
                ->waitForText('Informasi Kunjungan', 10)
                ->assertSee('Informasi Kunjungan');

            $this->assertRmePageHealthy($browser);
            $this->assertRmeKeywordPresent($browser);

            $browser->screenshot('rme-detail-smoke');
        });
    }
}
