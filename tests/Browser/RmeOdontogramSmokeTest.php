<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeOdontogramSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_odontogram_page_loads_without_errors(): void
    {
        $createdOdontogramId = null;

        try {
            $target = $this->resolveClinicVisitWithOdontogram();

            if (! $target) {
                $this->markTestSkipped('No clinic visit available for RME odontogram smoke test.');
            }

            $visit = $target['visit'];
            $createdOdontogramId = $target['created_odontogram_id'];

            $this->browse(function (Browser $browser) use ($visit) {
                $this->loginAsAdmin($browser)
                    ->visit(route('rme.visits.odontogram.show', $visit))
                    ->waitForText('Odontogram', 10)
                    ->assertSee('Odontogram');

                $this->assertRmePageHealthy($browser);

                $browser->screenshot('rme-odontogram-smoke');
            });
        } finally {
            $this->cleanupSmokeTestOdontogram($createdOdontogramId);
        }
    }
}
