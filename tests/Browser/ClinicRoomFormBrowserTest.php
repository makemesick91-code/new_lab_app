<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

/**
 * DMS-RME-REVISION-PROGRAM-8A / P8-N3 — the clinic-room create and edit forms
 * render as a working form in a real browser: their labels and controls are
 * present, a room can be saved and reopened, and neither page shows template
 * source or a server error. The base template printed its own source as text
 * (short_open_tag Off) or failed with HTTP 500 (On); rule 226 §2.
 *
 * Writes one room marked with the DUSK8A code prefix to the disposable Dusk
 * database. Never point Dusk at production (CG-13, CG-14).
 */
class ClinicRoomFormBrowserTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    /** Fragments that only appear when template source leaks into the page. */
    private const LEAK_MARKERS = ['@php', '@endphp', '<?php', '$typeLabels', '$statusLabels', '$room = $room'];

    public function test_clinic_room_create_and_edit_forms_render_and_save(): void
    {
        // Code and name are both unique per branch, so both are unique per run.
        $code = 'DUSK8A-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $this->browse(function (Browser $browser) use ($code) {
            $this->loginAsAdmin($browser)
                ->visit(route('settings.clinic-rooms.create'))
                ->waitForText('Tambah Ruangan', 10)
                ->assertSee('Simpan Ruangan')
                ->assertSelectHasOption('type', 'treatment_room')
                ->assertSelectHasOption('status', 'maintenance')
                ->assertSelected('status', 'active');

            $this->assertRmePageHealthy($browser);
            $this->assertNoTemplateLeak($browser);
            $browser->screenshot('p8a-clinic-room-create');

            $browser->type('code', $code)
                ->type('name', 'Ruang Uji '.$code)
                ->select('type', 'consultation_room')
                ->select('status', 'maintenance')
                ->type('description', 'Dibuat oleh uji browser Program 8A')
                ->press('Simpan Ruangan')
                ->waitForText($code, 10);

            $browser->visit(route('settings.clinic-rooms.index', ['search' => $code]))
                ->waitForText($code, 10)
                ->clickLink('Ubah')
                ->waitForText('Ubah Ruangan', 10)
                ->assertInputValue('code', $code)
                ->assertInputValue('name', 'Ruang Uji '.$code)
                ->assertSelected('type', 'consultation_room')
                ->assertSelected('status', 'maintenance')
                ->assertSee('Perbarui Ruangan');

            $this->assertRmePageHealthy($browser);
            $this->assertNoTemplateLeak($browser);
            $browser->screenshot('p8a-clinic-room-edit');
        });
    }

    private function assertNoTemplateLeak(Browser $browser): void
    {
        $text = $browser->driver->executeScript('return document.body.innerText;');

        foreach (self::LEAK_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $text, "Template source leaked into the page: {$marker}");
        }
    }
}
