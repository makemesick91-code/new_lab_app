<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmePrintSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_visit_print_page_loads_without_errors(): void
    {
        $visit = $this->resolveClinicVisit();

        if (! $visit) {
            $this->markTestSkipped('No clinic visit found in database for RME print smoke test.');
        }

        $this->browse(function (Browser $browser) use ($visit) {
            $this->loginAsAdmin($browser)
                ->visit(route('rme.visits.print', $visit))
                ->waitForText('Kunjungan', 10);

            $this->assertRmePageHealthy($browser);

            $browser->screenshot('rme-print-smoke');
        });
    }
}
