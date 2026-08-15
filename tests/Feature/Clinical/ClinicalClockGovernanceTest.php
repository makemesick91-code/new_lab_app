<?php

/**
 * LEGACY-RME-DATE-TZ-1 — the canonical clinical calendar contract.
 *
 * The defect: `config/app.php` hard-codes 'UTC' and never reads APP_TIMEZONE,
 * so the legacy archive's `env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC'))`
 * silently resolved to UTC in production. Between 16:00 and 24:00 UTC the clinic
 * is already living the next calendar day, so every "is this document historical
 * yet?" answer was computed eight hours out of frame.
 *
 * These tests pin the CONTRACT, not one call site: the timezone is canonical and
 * IANA, an unusable value fails closed instead of degrading to UTC, the process
 * default timezone cannot influence the answer, and the clinical day rolls over
 * at Asia/Makassar midnight — including across month, year and leap-day.
 *
 * Every test drives a frozen clock and restores it, so no frozen instant can
 * leak into a sibling test.
 */

use App\Support\Clinical\ClinicalClock;
use App\Support\Clinical\ClinicalTimezone;
use App\Support\Clinical\InvalidClinicalTimezoneException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

function clinicalClock(): ClinicalClock
{
    return app(ClinicalClock::class);
}

/**
 * Freeze an absolute instant, run the assertion, and always release the clock.
 */
function atInstant(string $utcInstant, callable $assert): void
{
    Carbon::setTestNow(CarbonImmutable::parse($utcInstant, 'UTC'));

    try {
        $assert();
    } finally {
        Carbon::setTestNow();
    }
}

afterEach(function () {
    // Belt and braces: a failed assertion inside a test that froze the clock
    // outside atInstant() must still not poison the next test.
    Carbon::setTestNow();
});

it('resolves the canonical clinical timezone as an IANA identifier', function () {
    expect(config(ClinicalTimezone::CONFIG_KEY))->toBe('Asia/Makassar')
        ->and(clinicalClock()->timezone())->toBe('Asia/Makassar')
        ->and(ClinicalTimezone::DEFAULT)->toBe('Asia/Makassar');
});

it('rejects offset aliases and abbreviations in favour of the IANA identifier', function () {
    // Each of these is either a fixed offset that can never carry a rule
    // change, or an abbreviation that is not a zone identifier at all.
    foreach (['WITA', 'UTC+8', 'GMT+8', '+08:00', 'Asia/Makasar', '', '   '] as $bad) {
        expect(ClinicalTimezone::isValid($bad))->toBeFalse("expected '{$bad}' to be rejected");
    }

    expect(ClinicalTimezone::isValid('Asia/Makassar'))->toBeTrue()
        ->and(ClinicalTimezone::isValid('UTC'))->toBeTrue();
});

it('fails closed on an invalid clinical timezone instead of falling back to UTC', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Asia/Makasar'); // real-world typo

    expect(fn () => clinicalClock()->timezone())
        ->toThrow(InvalidClinicalTimezoneException::class);

    // The critical property: it did not silently answer "UTC".
    expect(fn () => clinicalClock()->today())
        ->toThrow(InvalidClinicalTimezoneException::class);
});

it('fails closed on a blank clinical timezone', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, '');

    expect(fn () => clinicalClock()->today())
        ->toThrow(InvalidClinicalTimezoneException::class);
});

it('reports an invalid timezone through inspect() without throwing', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Not/AZone');

    $posture = clinicalClock()->inspect();

    expect($posture['valid'])->toBeFalse()
        ->and($posture['effective'])->toBeNull()
        ->and($posture['canonical'])->toBeFalse()
        ->and($posture['expected'])->toBe('Asia/Makassar')
        ->and($posture['message'])->toContain('fail closed');
});

it('derives the clinical date from the clinical timezone, not the process default', function () {
    // The suite runs with a UTC process default (config/app.php pins 'UTC').
    // That is the CORRECT posture for technical instants and must not leak into
    // the clinical calendar.
    expect(date_default_timezone_get())->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC');

    atInstant('2026-08-13T16:00:00Z', function () {
        expect(clinicalClock()->todayString())->toBe('2026-08-14')
            // Same instant, UTC calendar — proves the two frames genuinely differ
            // here, which is exactly the window the old code got wrong.
            ->and(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-08-13');
    });
});

it('rolls the clinical day over at Asia/Makassar midnight, to the second', function () {
    atInstant('2026-08-13T15:59:59Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-08-13'));
    atInstant('2026-08-13T16:00:00Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-08-14'));
    atInstant('2026-08-13T16:00:01Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-08-14'));
});

it('maps an instant to a clinical date without consulting the current time', function () {
    $clock = clinicalClock();

    expect($clock->toClinicalDateString('2026-08-13T15:59:59Z'))->toBe('2026-08-13')
        ->and($clock->toClinicalDateString('2026-08-13T16:00:00Z'))->toBe('2026-08-14')
        ->and($clock->toClinicalDateString('2026-08-13T16:00:01Z'))->toBe('2026-08-14')
        // An explicit WITA wall-clock instant maps to its own calendar day.
        ->and($clock->toClinicalDateString('2026-08-14T00:00:00+08:00'))->toBe('2026-08-14');
});

it('rolls over correctly across a month boundary', function () {
    // 2026-08-31 23:59:59 WITA == 2026-08-31 15:59:59 UTC
    atInstant('2026-08-31T15:59:59Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-08-31'));
    atInstant('2026-08-31T16:00:00Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-09-01'));
});

it('rolls over correctly across a year boundary', function () {
    // 2026-12-31 23:59:59 WITA == 2026-12-31 15:59:59 UTC
    atInstant('2026-12-31T15:59:59Z', fn () => expect(clinicalClock()->todayString())->toBe('2026-12-31'));
    atInstant('2026-12-31T16:00:00Z', fn () => expect(clinicalClock()->todayString())->toBe('2027-01-01'));
});

it('handles the leap day as an ordinary calendar date', function () {
    atInstant('2028-02-28T16:00:00Z', fn () => expect(clinicalClock()->todayString())->toBe('2028-02-29'));
    atInstant('2028-02-29T15:59:59Z', fn () => expect(clinicalClock()->todayString())->toBe('2028-02-29'));
    atInstant('2028-02-29T16:00:00Z', fn () => expect(clinicalClock()->todayString())->toBe('2028-03-01'));
});

it('never lets a request-supplied timezone influence the clinical day', function () {
    atInstant('2026-08-13T16:00:00Z', function () {
        // A hostile client asserting its own timezone/date in every channel a
        // request can carry. None of them is an input to the clinical clock:
        // the value comes from server configuration only.
        $response = $this->withHeaders([
            'X-Timezone' => 'UTC',
            'Time-Zone' => 'Pacific/Kiritimati',
        ])->withCookie('timezone', 'UTC')
            ->get('/health/live?timezone=UTC&today=2026-08-13&current_date=2026-08-13');

        $response->assertOk();

        expect(clinicalClock()->timezone())->toBe('Asia/Makassar')
            ->and(clinicalClock()->todayString())->toBe('2026-08-14');
    });
});

it('keeps the technical instant frame separate from the clinical calendar', function () {
    atInstant('2026-08-13T16:00:00Z', function () {
        // Technical timestamps stay on the application's UTC architecture...
        expect(now()->toDateString())->toBe('2026-08-13')
            ->and(config('app.timezone'))->toBe('UTC')
            // ...while the clinical calendar has already turned over.
            ->and(clinicalClock()->todayString())->toBe('2026-08-14');
    });
});

it('reports a canonical posture that is safe to print as evidence', function () {
    $posture = clinicalClock()->inspect();

    expect($posture['valid'])->toBeTrue()
        ->and($posture['effective'])->toBe('Asia/Makassar')
        ->and($posture['canonical'])->toBeTrue()
        ->and($posture['process_default'])->toBe('UTC');
});

it('exposes the clinical date diagnostic as a read-only command', function () {
    $this->artisan('clinical:date-diagnose', [
        '--instant' => ['2026-08-13T15:59:59Z', '2026-08-13T16:00:00Z'],
        '--strict' => true,
    ])->assertExitCode(0);
});

it('fails the diagnostic command when the clinical timezone is unusable', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Not/AZone');

    $this->artisan('clinical:date-diagnose')->assertExitCode(1);
});

it('fails the diagnostic command under --strict on a non-canonical timezone', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'UTC');

    // Valid identifier, so it runs...
    $this->artisan('clinical:date-diagnose')->assertExitCode(0);
    // ...but a release gate must not accept a non-canonical clinical calendar.
    $this->artisan('clinical:date-diagnose', ['--strict' => true])->assertExitCode(1);
});
