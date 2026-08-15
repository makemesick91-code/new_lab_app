<?php

/**
 * LEGACY-RME-DATE-TZ-1 — the legacy archive date rules on the clinical calendar.
 *
 * THE REGRESSION THIS PINS. `latest_rme_date < today` is only well-defined once
 * "today" is. With the old UTC-anchored clock, a document dated the clinic's
 * current day was refused as "in the future" for the first 16 hours of the UTC
 * day and accepted for the last 8 — the same document, two answers, decided by
 * nothing but the hour of submission.
 *
 * The rules themselves are unchanged and stay strict:
 *   latest == clinicalToday  -> NOT yet historical (refused)
 *   latest <  clinicalToday  -> the date-age gate passes
 *
 * Stored DATEs are never shifted. A legacy date a human read off a document is
 * historical evidence, not an instant, and moving it to "fix" a clock would
 * corrupt the archive.
 */

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationQuotaService;
use App\Modules\LegacyRme\Services\LegacyRmeRolloutReadinessService;
use App\Support\Clinical\ClinicalClock;
use App\Support\Clinical\ClinicalTimezone;
use App\Support\Clinical\InvalidClinicalTimezoneException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

function lrmeTzRules(): LegacyRmeDateRuleService
{
    return app(LegacyRmeDateRuleService::class);
}

function lrmeAtInstant(string $utcInstant, callable $assert): void
{
    Carbon::setTestNow(CarbonImmutable::parse($utcInstant, 'UTC'));

    try {
        $assert();
    } finally {
        Carbon::setTestNow();
    }
}

afterEach(function () {
    Carbon::setTestNow();
});

it('anchors the legacy date rules to the canonical clinical calendar', function () {
    expect(lrmeTzRules()->timezone())->toBe('Asia/Makassar');
});

it('refuses a document dated the current CLINICAL day even while UTC is still on the previous date', function () {
    // 16:00:00Z == 00:00:00 the next day in Asia/Makassar. The clinic is on
    // 2026-08-14; UTC is still on 2026-08-13.
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $result = lrmeTzRules()->evaluate($patient, '2026-08-14');

        expect($result->failed())->toBeTrue()
            ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE)
            ->and($result->context['today'])->toBe('2026-08-14')
            ->and($result->context['clinical_timezone'])->toBe('Asia/Makassar');
    });
});

it('passes the date-age gate for the previous clinical day at the exact WITA midnight instant', function () {
    // THE CORE CASE. 2026-08-13 is genuinely historical the moment the clinic
    // turns over to 2026-08-14, even though the UTC calendar still says
    // 2026-08-13. A UTC-anchored clock refused this document.
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $result = lrmeTzRules()->evaluate($patient, '2026-08-13');

        expect($result->passed)->toBeTrue()
            ->and($result->context['today'])->toBe('2026-08-14')
            ->and($result->context['reference_mode'])
            ->toBe(LegacyRmeDateRuleService::REFERENCE_MODE_NO_NATIVE_REFERENCE);
    });
});

it('still refuses that same document one second before the clinical day turns over', function () {
    // 15:59:59Z == 23:59:59 WITA on 2026-08-13. The clinic is still living that
    // day, so a document dated 2026-08-13 is not yet historical.
    lrmeAtInstant('2026-08-13T15:59:59Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $result = lrmeTzRules()->evaluate($patient, '2026-08-13');

        expect($result->failed())->toBeTrue()
            ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE)
            ->and($result->context['today'])->toBe('2026-08-13');
    });
});

it('keeps the boundary stable one second after the clinical day turns over', function () {
    lrmeAtInstant('2026-08-13T16:00:01Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        expect(lrmeTzRules()->evaluate($patient, '2026-08-13')->passed)->toBeTrue();
    });
});

it('holds the boundary across month and year rollovers', function () {
    lrmeAtInstant('2026-08-31T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient();
        expect(lrmeTzRules()->evaluate($patient, '2026-08-31')->passed)->toBeTrue()
            ->and(lrmeTzRules()->evaluate($patient, '2026-09-01')->failed())->toBeTrue();
    });

    lrmeAtInstant('2026-12-31T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient();
        expect(lrmeTzRules()->evaluate($patient, '2026-12-31')->passed)->toBeTrue()
            ->and(lrmeTzRules()->evaluate($patient, '2027-01-01')->failed())->toBeTrue();
    });
});

it('uses ONE clock for upload validation and publish revalidation', function () {
    // Split-brain guard. Publish re-enters the same evaluate(), so at a single
    // frozen instant both stages must agree exactly — including on the boundary
    // day, where a UTC/WITA split would have shown up as disagreement.
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $upload = lrmeTzRules()->evaluate($patient, '2026-08-13');
        // The publish gate calls evaluate() with the persisted pair.
        $publish = lrmeTzRules()->evaluate($patient, '2026-08-13', '2026-08-13');

        expect($upload->passed)->toBe($publish->passed)
            ->and($upload->context['today'])->toBe($publish->context['today'])
            ->and($upload->context['clinical_timezone'])->toBe($publish->context['clinical_timezone'])
            ->and($upload->context['today'])->toBe('2026-08-14');
    });
});

it('lets real time advance the clinical day between upload and publish', function () {
    $patient = null;

    // Refused at 23:59:59 WITA...
    lrmeAtInstant('2026-08-13T15:59:59Z', function () use (&$patient) {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
        expect(lrmeTzRules()->evaluate($patient, '2026-08-13')->failed())->toBeTrue();
    });

    // ...and accepted one second later, because the CLOCK MOVED, not because a
    // second clock disagreed.
    lrmeAtInstant('2026-08-13T16:00:00Z', function () use (&$patient) {
        expect(lrmeTzRules()->evaluate($patient, '2026-08-13')->passed)->toBeTrue();
    });
});

it('compares the native RME cutoff as DATE vs DATE, unaffected by the clock', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    // Same two dates, evaluated on either side of the clinical midnight: the
    // cutoff verdict must be identical, because neither date is an instant.
    foreach (['2026-08-13T15:59:59Z', '2026-08-13T16:00:00Z'] as $instant) {
        lrmeAtInstant($instant, function () use ($patient) {
            expect(lrmeTzRules()->evaluate($patient, '2022-03-09')->passed)->toBeTrue()
                ->and(lrmeTzRules()->evaluate($patient, '2022-03-10')->code)
                ->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME)
                ->and(lrmeTzRules()->evaluate($patient, '2022-03-09')->context['earliest_native_rme_date'])
                ->toBe('2022-03-10');
        });
    }
});

it('preserves NO_NATIVE_REFERENCE as a valid state on the clinical calendar', function () {
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $result = lrmeTzRules()->evaluate($patient, '2020-01-01');

        expect($result->passed)->toBeTrue()
            ->and($result->context['earliest_native_rme_date'])->toBeNull()
            ->and($result->context['reference_mode'])
            ->toBe(LegacyRmeDateRuleService::REFERENCE_MODE_NO_NATIVE_REFERENCE);
    });
});

it('never shifts a stored legacy DATE when the clinical clock moves', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $patient->branch_id,
        'rme_date' => '2026-08-13',
        'latest_rme_date' => '2026-08-13',
    ]);

    // Read the persisted values at instants on both sides of WITA midnight, and
    // months later. A DATE is historical evidence and must never move.
    foreach (['2026-08-13T15:59:59Z', '2026-08-13T16:00:00Z', '2027-03-01T09:00:00Z'] as $instant) {
        lrmeAtInstant($instant, function () use ($record) {
            $fresh = LegacyRmeRecord::query()->findOrFail($record->getKey());

            expect($fresh->rme_date->toDateString())->toBe('2026-08-13')
                ->and($fresh->latest_rme_date->toDateString())->toBe('2026-08-13');
        });
    }
});

it('preserves the single-date and multi-date range semantics', function () {
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        // Single date: the range collapses, both ends report the same day.
        $single = lrmeTzRules()->evaluate($patient, '2020-05-04');
        expect($single->passed)->toBeTrue()
            ->and($single->context['selected_rme_date'])->toBe('2020-05-04')
            ->and($single->context['latest_rme_date'])->toBe('2020-05-04');

        // Multi-date: earliest is representative, latest is the safety bound.
        $range = lrmeTzRules()->evaluate($patient, '2020-05-04', '2021-06-07');
        expect($range->passed)->toBeTrue()
            ->and($range->context['selected_rme_date'])->toBe('2020-05-04')
            ->and($range->context['latest_rme_date'])->toBe('2021-06-07');

        // The LATEST end is what the today-bound is applied to.
        $straddling = lrmeTzRules()->evaluate($patient, '2020-05-04', '2026-08-14');
        expect($straddling->failed())->toBeTrue()
            ->and($straddling->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
    });
});

it('keeps the birth-date rule timezone-invariant', function () {
    foreach (['2026-08-13T15:59:59Z', '2026-08-13T16:00:00Z'] as $instant) {
        lrmeAtInstant($instant, function () {
            $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

            // Equal to the birth date is accepted; earlier is not.
            expect(lrmeTzRules()->evaluate($patient, '1990-01-01')->passed)->toBeTrue()
                ->and(lrmeTzRules()->evaluate($patient, '1989-12-31')->code)
                ->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH);
        });
    }
});

it('charges the migration quota to the same clinical day the rules judge', function () {
    // A quota bucket keyed to UTC while eligibility is keyed to WITA would reset
    // mid-morning and charge a document to a day it was not judged against.
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        expect(app(LegacyRmeMigrationQuotaService::class)->today()->toDateString())
            ->toBe('2026-08-14')
            ->and(app(LegacyRmeMigrationQuotaService::class)->today()->toDateString())
            ->toBe(app(ClinicalClock::class)->todayString());
    });
});

it('fails legacy date evaluation closed when the clinical timezone is unusable', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Asia/Makasar');

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    // No silent UTC fallback: a misconfigured deployment refuses the decision
    // rather than making it in an unknown frame.
    expect(fn () => lrmeTzRules()->evaluate($patient, '2020-01-01'))
        ->toThrow(InvalidClinicalTimezoneException::class);
});

it('reports the clinical timezone posture through the rollout readiness gate', function () {
    $checks = collect(app(LegacyRmeRolloutReadinessService::class)->report()['checks']);

    $check = $checks->firstWhere('id', 'clinical_timezone');

    expect($check)->not->toBeNull()
        ->and($check['status'])->toBe('GO')
        ->and($check['context']['clinical_timezone'])->toBe('Asia/Makassar')
        ->and($check['context']['process_default'])->toBe('UTC');
});

it('fails the rollout readiness gate when the clinical timezone is invalid', function () {
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Not/AZone');

    $report = app(LegacyRmeRolloutReadinessService::class)->report();
    $check = collect($report['checks'])->firstWhere('id', 'clinical_timezone');

    expect($check['status'])->toBe('FAIL')
        ->and($report['decision'])->toBe('NO_GO');
});

it('does not persist anything while evaluating date rules', function () {
    lrmeAtInstant('2026-08-13T16:00:00Z', function () {
        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

        $importsBefore = LegacyRmeImport::query()->count();
        $recordsBefore = LegacyRmeRecord::query()->count();

        lrmeTzRules()->evaluate($patient, '2026-08-13');
        lrmeTzRules()->evaluate($patient, '2026-08-14');

        expect(LegacyRmeImport::query()->count())->toBe($importsBefore)
            ->and(LegacyRmeRecord::query()->count())->toBe($recordsBefore);
    });
});
