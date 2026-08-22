<?php

use App\Services\Foundation\FiveBranchRolloutReadinessService;
use App\Services\Foundation\RestoreDrillEvidenceService;
use App\Support\Foundation\RestoreDrillTimestampParser;
use Illuminate\Support\Carbon;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

/**
 * RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1
 *
 * Restore-drill evidence must never obtain a trustworthy age from an
 * untrustworthy timestamp. These tests pin the canonical evidence timestamp
 * grammar, the freshness boundary, and — most importantly — that a malformed,
 * relative, or future-dated `completed_at` can never read as recent evidence.
 *
 * Time is frozen in every test: none of these assertions may depend on the wall
 * clock, the current month, or a day/month/year rollover happening to be near.
 */

/** Frozen reference instant used by the whole file (UTC). */
const RD_NOW = '2026-08-23T12:00:00Z';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(RD_NOW));
    config()->set('rollout_readiness.thresholds.restore_drill_stale_hours', 720);
    config()->set('rollout_readiness.thresholds.restore_drill_future_skew_minutes', 5);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob(storage_path('app/readiness/restore-drills/tsfaith-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

function rdParser(): RestoreDrillTimestampParser
{
    return app(RestoreDrillTimestampParser::class);
}

function rdService(): RestoreDrillEvidenceService
{
    return app(RestoreDrillEvidenceService::class);
}

/**
 * Write otherwise-valid, otherwise-GO evidence so the ONLY variable under test
 * is the timestamp. Anything that changes the verdict here is attributable to
 * the timestamp alone.
 *
 * @param  array<string, mixed>  $overrides
 */
function rdEvidence(array $overrides = []): string
{
    $payload = array_replace([
        'schema_version' => 1,
        'drill_id' => 'roll-5-1a-20260820-010203',
        'environment' => 'staging',
        // Absolute, non-project path => local-backup existence check is skipped.
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 123456,
        'restore_target' => 'daengtisiams_restore_drill_20260820',
        'production_overwrite' => false,
        'started_at' => '2026-08-20T11:58:00Z',
        'completed_at' => '2026-08-20T12:00:00Z',
        'duration_seconds' => 120,
        'operator' => 'ops',
        'commands_summary' => ['createdb', 'psql restore (password hidden)', 'dropdb'],
        'verification' => [
            'db_connectivity' => 'GO',
            'migration_consistency' => 'GO',
            'app_boot' => 'GO',
            'health_routes' => 'GO',
            'sample_readonly_queries' => 'GO',
            'pii_redaction_confirmed' => true,
        ],
        'decision' => 'GO',
        'notes' => ['safe'],
    ], $overrides);

    $dir = storage_path('app/readiness/restore-drills');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/tsfaith-'.uniqid().'.json';
    file_put_contents($path, json_encode($payload));
    // The file itself is always FRESH on disk. Any "recent" verdict therefore
    // has to come from the timestamp, never from the file's mtime.

    return $path;
}

// ---------------------------------------------------------------------------
// Canonical grammar — the parser accepts exactly what the producer writes.
// ---------------------------------------------------------------------------

it('accepts the canonical UTC evidence timestamp the drill script produces', function () {
    // scripts/rollout-restore-drill.sh: date -u +%Y-%m-%dT%H:%M:%SZ
    $literal = gmdate('Y-m-d\TH:i:s\Z', 1_755_000_000);

    $parsed = rdParser()->parse($literal);

    expect($parsed)->not->toBeNull()
        ->and($parsed->getTimestamp())->toBe(1_755_000_000)
        ->and($parsed->format('Y-m-d\TH:i:s\Z'))->toBe($literal);
});

it('accepts a valid leap day and preserves the exact instant', function () {
    $parsed = rdParser()->parse('2024-02-29T10:30:00Z');

    expect($parsed)->not->toBeNull()
        ->and($parsed->format('Y-m-d\TH:i:s\Z'))->toBe('2024-02-29T10:30:00Z');
});

// ---------------------------------------------------------------------------
// Faithfulness — a literal the parser would have to CHANGE is not evidence.
// ---------------------------------------------------------------------------

it('rejects timestamps that only parse by being normalised or rolled over', function (string $literal) {
    expect(rdParser()->isFaithful($literal))->toBeFalse();
})->with([
    'invalid calendar date (Feb 30)' => '2026-02-30T10:00:00Z',
    'non-leap Feb 29' => '2026-02-29T10:00:00Z',
    'day zero' => '2026-08-00T10:00:00Z',
    'month zero' => '2026-00-15T10:00:00Z',
    'month thirteen' => '2026-13-01T10:00:00Z',
    'day thirty-two' => '2026-08-32T10:00:00Z',
    'hour twenty-five' => '2026-08-20T25:00:00Z',
    'minute sixty-one' => '2026-08-20T10:61:00Z',
    'second sixty-one' => '2026-08-20T10:00:61Z',
]);

it('rejects relative-date expressions — evidence timestamps are data, not commands', function (string $literal) {
    expect(rdParser()->isFaithful($literal))->toBeFalse();
})->with([
    'trailing modifier on a stale literal' => '2025-01-01T00:00:00Z +2 years',
    'trailing negative modifier' => '2026-08-20T12:00:00Z -1 year',
    'bare relative' => '-1 year',
    'yesterday' => 'yesterday',
    'tomorrow' => 'tomorrow',
    'now' => 'now',
]);

it('rejects malformed, padded, or empty literals', function (?string $literal) {
    expect(rdParser()->isFaithful($literal))->toBeFalse();
})->with([
    'trailing junk' => '2025-01-01T00:00:00Z XYZ',
    'leading junk' => 'at 2025-01-01T00:00:00Z',
    'surrounding whitespace' => ' 2026-08-20T12:00:00Z ',
    'whitespace only' => '   ',
    'empty' => '',
    'null' => null,
    'not a date' => 'not-a-date',
    'epoch seconds' => '1755000000',
    'missing Z suffix' => '2026-08-20T12:00:00',
    'space instead of T' => '2026-08-20 12:00:00Z',
]);

// ---------------------------------------------------------------------------
// FALSE-FRESH — the defect this sprint exists to close.
// ---------------------------------------------------------------------------

it('never reports genuinely stale evidence as fresh via a relative modifier', function () {
    // BEFORE: strtotime('2025-01-01T00:00:00Z +2 years') => 2027-01-01 (future),
    // then max(0.0, negative) clamped the age to 0.0h => "0.0 jam lalu" => GO.
    $result = rdService()->evaluate(rdEvidence(['completed_at' => '2025-01-01T00:00:00Z +2 years']));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['age_hours'])->toBeNull()
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_UNPARSEABLE)
        ->and($result['details']['issues'])->toContain('evidence_timestamp_unparseable')
        ->and($result['unsafe'])->toBeFalse();
});

it('never treats a future-dated drill as the freshest possible evidence', function () {
    // BEFORE: a future instant made (now - ts) negative and max(0.0, …) reported
    // 0.0 hours — i.e. "the drill just finished".
    $result = rdService()->evaluate(rdEvidence(['completed_at' => '2030-01-01T00:00:00Z']));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['age_hours'])->toBeNull()
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_FUTURE)
        ->and($result['details']['issues'])->toContain('evidence_timestamp_future');
});

it('never inherits the evidence file mtime as the drill age when the timestamp is unparseable', function () {
    // BEFORE: strtotime() failed, so the age fell back to filemtime(). The file
    // is written now, so malformed evidence read as 0.0 hours old => GO.
    $result = rdService()->evaluate(rdEvidence(['completed_at' => '2026-13-01T10:00:00Z']));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['age_hours'])->toBeNull()
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_UNPARSEABLE);
});

it('never treats a day-zero literal as recent evidence', function () {
    // BEFORE: '2026-08-00T…' normalised BACKWARD to 2026-07-31 => 554h => GO.
    $result = rdService()->evaluate(rdEvidence(['completed_at' => '2026-08-00T10:00:00Z']));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_UNPARSEABLE);
});

it('never lets an unageable timestamp reach GO through the null-age path', function (string $literal) {
    // The stale test is `age !== null && age > threshold`. A null age is NOT
    // stale — so an untrusted timestamp must be blocked by its own explicit
    // issue, never fall through the staleness check into GO.
    $result = rdService()->evaluate(rdEvidence(['completed_at' => $literal]));

    expect($result['details']['stale'])->toBeFalse()
        ->and($result['details']['age_hours'])->toBeNull()
        ->and($result['status'])->not->toBe(RestoreDrillEvidenceService::GO);
})->with([
    'unparseable' => '2026-08-32T10:00:00Z',
    'future' => '2027-01-01T00:00:00Z',
    'relative' => 'yesterday',
]);

// ---------------------------------------------------------------------------
// FALSE-EXPIRED — an invalid literal must not manufacture a stale verdict either.
// ---------------------------------------------------------------------------

it('reports an invalid literal as untrusted rather than fabricating a stale age', function (string $literal) {
    // BEFORE: month-zero rolled BACKWARD (2025-12-15) and a bare '-1 year'
    // resolved a year back — both produced a confident but fabricated
    // "evidence_stale" age. Malformed evidence is unknown, not old.
    $result = rdService()->evaluate(rdEvidence(['completed_at' => $literal]));

    expect($result['details']['issues'])->toContain('evidence_timestamp_unparseable')
        ->and($result['details']['issues'])->not->toContain('evidence_stale')
        ->and($result['details']['age_hours'])->toBeNull();
})->with([
    'month zero rolls backward' => '2026-00-15T10:00:00Z',
    'bare negative modifier' => '-1 year',
    'invalid Feb 30 rolls forward' => '2026-02-30T10:00:00Z',
    'trailing junk' => '2025-01-01T00:00:00Z XYZ',
]);

it('distinguishes a missing timestamp from a stale one', function (mixed $literal) {
    $result = rdService()->evaluate(rdEvidence(['completed_at' => $literal]));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_MISSING)
        ->and($result['details']['age_hours'])->toBeNull()
        ->and($result['details']['issues'])->toContain('evidence_timestamp_missing');
})->with([
    'null' => null,
    'empty string' => '',
    'non-string' => 12345,
]);

// ---------------------------------------------------------------------------
// TRUE-FRESH / TRUE-STALE controls — the fix must not break real evidence.
// ---------------------------------------------------------------------------

it('still returns GO for valid, recent, canonical staging evidence', function () {
    $recent = gmdate('Y-m-d\TH:i:s\Z', Carbon::parse(RD_NOW)->getTimestamp() - 72 * 3600);

    $result = rdService()->evaluate(rdEvidence(['completed_at' => $recent]));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::GO)
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_VALID)
        ->and($result['details']['age_hours'])->toBe(72.0)
        ->and($result['details']['stale'])->toBeFalse();
});

it('still reports genuinely old canonical evidence as stale, not as malformed', function () {
    $old = gmdate('Y-m-d\TH:i:s\Z', Carbon::parse(RD_NOW)->getTimestamp() - 1000 * 3600);

    $result = rdService()->evaluate(rdEvidence(['completed_at' => $old]));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and($result['details']['stale'])->toBeTrue()
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_VALID)
        ->and($result['details']['age_hours'])->toBe(1000.0)
        ->and($result['details']['issues'])->toContain('evidence_stale');
});

it('lets fresh valid evidence recover the GO state after an untrusted timestamp', function () {
    $bad = rdService()->evaluate(rdEvidence(['completed_at' => 'yesterday']));
    expect($bad['status'])->toBe(RestoreDrillEvidenceService::WATCH);

    $good = rdService()->evaluate(rdEvidence([
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z', Carbon::parse(RD_NOW)->getTimestamp() - 3600),
    ]));

    expect($good['status'])->toBe(RestoreDrillEvidenceService::GO)
        ->and($good['details']['age_hours'])->toBe(1.0);
});

// ---------------------------------------------------------------------------
// Boundary — pinned inclusively, no ambiguity.
// ---------------------------------------------------------------------------

it('pins the freshness boundary: age <= threshold is fresh, strictly greater is stale', function (int $ageHours, bool $expectStale) {
    $ts = gmdate('Y-m-d\TH:i:s\Z', Carbon::parse(RD_NOW)->getTimestamp() - $ageHours * 3600);

    $result = rdService()->evaluate(rdEvidence(['completed_at' => $ts]));

    expect($result['details']['age_hours'])->toBe((float) $ageHours)
        ->and($result['details']['stale'])->toBe($expectStale)
        ->and($result['status'])->toBe($expectStale ? RestoreDrillEvidenceService::WATCH : RestoreDrillEvidenceService::GO);
})->with([
    'just inside (719h)' => [719, false],
    'exactly at threshold (720h)' => [720, false],
    'just outside (721h)' => [721, true],
]);

// ---------------------------------------------------------------------------
// Clock skew — bounded tolerance, never unbounded future acceptance.
// ---------------------------------------------------------------------------

it('tolerates sub-threshold clock jitter but rejects a meaningfully future timestamp', function (int $futureMinutes, string $expected) {
    $ts = gmdate('Y-m-d\TH:i:s\Z', Carbon::parse(RD_NOW)->getTimestamp() + $futureMinutes * 60);

    $result = rdService()->evaluate(rdEvidence(['completed_at' => $ts]));

    expect($result['details']['timestamp_status'])->toBe($expected);
})->with([
    'within 5-minute skew' => [2, RestoreDrillEvidenceService::TS_VALID],
    'beyond 5-minute skew' => [30, RestoreDrillEvidenceService::TS_FUTURE],
    'far future' => [60 * 24 * 365, RestoreDrillEvidenceService::TS_FUTURE],
]);

// ---------------------------------------------------------------------------
// Calendar rollover — age stays continuous, never inferred from "now".
// ---------------------------------------------------------------------------

it('computes a continuous age across day, month, and year rollovers', function (string $now, string $completed, float $expectedAge) {
    Carbon::setTestNow(Carbon::parse($now));

    $result = rdService()->evaluate(rdEvidence(['completed_at' => $completed]));

    expect($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_VALID)
        ->and($result['details']['age_hours'])->toBe($expectedAge);
})->with([
    'day rollover' => ['2026-08-23T01:00:00Z', '2026-08-22T23:00:00Z', 2.0],
    'month rollover' => ['2026-09-01T01:00:00Z', '2026-08-31T23:00:00Z', 2.0],
    'year rollover (Dec 31 -> Jan 1)' => ['2027-01-01T01:00:00Z', '2026-12-31T23:00:00Z', 2.0],
    'leap-day rollover' => ['2024-03-01T01:00:00Z', '2024-02-29T23:00:00Z', 2.0],
]);

// ---------------------------------------------------------------------------
// Operator visibility + downstream rollout decision.
// ---------------------------------------------------------------------------

it('surfaces the timestamp trust state to operators in the command JSON', function () {
    $path = rdEvidence(['completed_at' => '2025-01-01T00:00:00Z +2 years']);

    $this->artisan('rollout:restore-drill-evidence', ['--path' => $path, '--json' => true])
        ->assertExitCode(0);

    $result = rdService()->evaluate($path);
    expect($result['details'])->toHaveKey('timestamp_status')
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_UNPARSEABLE);
});

it('does not let a forged-fresh timestamp clear the restore-drill rollout signal', function () {
    $path = rdEvidence(['completed_at' => '2025-01-01T00:00:00Z +2 years']);
    config()->set('rollout_readiness.paths.restore_drill_evidence', [$path]);

    $signals = app(FiveBranchRolloutReadinessService::class)->collect()['signals'];
    $signal = collect($signals)->firstWhere('key', 'restore_drill_evidence');

    expect($signal)->not->toBeNull()
        ->and($signal['status'])->not->toBe(RestoreDrillEvidenceService::GO);
});
