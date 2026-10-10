<?php

/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — live field overlay on the KTP
 * camera preview (server-rendered side).
 *
 * The overlay itself runs in the browser (tests/js/ktp-live-overlay.test.mjs).
 * What the server decides is pinned here: the overlay markup exists only inside
 * the camera block, which is rendered only for an operator the pilot gate allows;
 * it comes after the D7 consent panel; it carries no script, no URL and no OCR
 * text; and the release adds no route — the parse endpoint and its consent and
 * pilot checks are unchanged. All identities are FICTIONAL.
 */

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    ktpPilotScope();
    ktpPilotFlag(false);
});

afterEach(fn () => Carbon::setTestNow());

it('renders the live overlay inside the camera block for a pilot operator, after the consent panel', function (string $routeName) {
    $operator = ktpPilotOperator();

    $html = $this->actingAs($operator)->get(route($routeName))->assertOk()->getContent();

    expect($html)->toContain('data-ktp-live-overlay')
        ->and($html)->toContain('data-ktp-live-panel')
        ->and($html)->toContain('data-ktp-live-guidance')
        ->and($html)->toContain('data-ktp-camera-guide')
        ->and($html)->toContain('data-ktp-live-level="red"')
        ->and($html)->toContain('data-ktp-live-level="yellow"')
        ->and($html)->toContain('data-ktp-live-level="green"')
        ->and($html)->toContain('Hijau bukan jaminan hasil baca benar');

    $consent = strpos($html, 'data-ktp-consent ');
    $camera = strpos($html, 'data-ktp-camera>');
    $overlay = strpos($html, 'data-ktp-live-overlay');
    expect($consent)->toBeLessThan($camera)
        ->and($camera)->toBeLessThan($overlay);
})->with(['settings.patients.create', 'rme.visits.create']);

it('starts hidden and decorative: no state is rendered by the server', function () {
    $operator = ktpPilotOperator();

    $html = $this->actingAs($operator)->get(route('settings.patients.create'))->assertOk()->getContent();

    // The SVG layer is hidden and ignored by screen readers; the indicator is
    // the accessible surface (role=status, polite), also hidden until the
    // camera is open.
    expect($html)->toMatch('/<svg data-ktp-live-overlay class="[^"]*\bhidden\b[^"]*" aria-hidden="true" focusable="false"><\/svg>/')
        ->and($html)->toMatch('/class="[^"]*\bhidden\b[^"]*" data-ktp-live-panel role="status" aria-live="polite"/');
    foreach (['red', 'yellow', 'green'] as $level) {
        expect($html)->toMatch('/data-ktp-live-level="'.$level.'" class="hidden /');
    }
    // No server-side tracking state, statistics or corners.
    expect($html)->not->toContain('data-live-stats')
        ->and($html)->not->toContain('data-live-level');
});

it('gives no live overlay to an operator outside the pilot, or while the flag is off', function () {
    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, ktpPilotBranch());
    ktpPilotOperator(); // the pilot is armed for somebody else at the same branch

    $this->actingAs($outsider)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('data-ktp-live-overlay', false)
        ->assertDontSee('data-ktp-camera-open', false)
        ->assertSee('Unggah foto KTP secara manual');

    $operator = ktpPilotOperator();
    ktpPilotFlag(false);
    $this->actingAs($operator)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('data-ktp-live-overlay', false);
});

it('ships the overlay markup with no inline script, event handler or external URL', function () {
    $source = file_get_contents(resource_path('views/settings/patients/_ktp-scan.blade.php'));
    $start = strpos($source, 'data-ktp-live-stage');
    $end = strpos($source, 'data-ktp-capture');
    $block = substr($source, $start, $end - $start);

    expect($block)->not->toBe('')
        ->and($block)->not->toMatch('/<script|\son[a-z]+=|https?:\/\//i');
});

it('adds no route: the live overlay talks to no server endpoint', function () {
    $ktpRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => is_string($name) && str_contains($name, 'ktp'))
        ->sort()
        ->values()
        ->all();

    expect($ktpRoutes)->toBe([
        'settings.patients.ktp-scan.parse-ocr',
        'settings.patients.ktp-scan.upload-temp',
    ]);
});

it('still refuses OCR text without the D7 consent after this release', function () {
    $operator = ktpPilotOperator();

    $this->actingAs($operator)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => [['text' => 'NIK : 7371015708900003', 'confidence' => 92]]])
        ->assertStatus(422);

    $this->actingAs($operator)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => [['text' => 'NIK : 7371015708900003', 'confidence' => 92]]] + ktpConsent())
        ->assertOk();
});
