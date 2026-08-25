<?php

use App\Services\Foundation\FiveBranchRolloutReadinessService;
use App\Services\Foundation\RestoreDrillEvidenceService;
use App\Support\Foundation\RestoreDrillEvidenceReader;

/**
 * RESTORE-DRILL-EVIDENCE-READ-STATE-1
 *
 * Restore-drill evidence stops being trustworthy at a specific stage, and the
 * operator has to be told WHICH stage. Before this contract existed, the read
 * outcome was flattened — `(string) @file_get_contents($path)` turned a failed
 * read into an empty string, so a file nobody could read arrived at the JSON
 * decoder looking exactly like a malformed document, and a file that existed
 * but held nothing was reported as no file at all.
 *
 * The readiness verdict was never wrong (every one of these is non-GO), so this
 * is a state-integrity defect rather than a false-green one. These tests pin
 * BOTH halves of the fix:
 *
 *  - each stage now reports itself truthfully, AND
 *  - not one of them became more permissive in the process.
 *
 * The second half is the one that must never regress. A reason string is a
 * convenience; the fail-closed verdict is the safety property.
 */
uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

/** A complete, safe, fresh staging drill payload. */
function rdrsValidPayload(array $overrides = []): array
{
    return array_merge([
        'schema_version' => 1,
        'drill_id' => 'rdrs1-20260823-010203',
        'environment' => 'staging',
        // Absolute, non-project path => the local-backup existence check is skipped.
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 123456,
        'restore_target' => 'daengtisiams_restore_drill_20260823',
        'production_overwrite' => false,
        'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'duration_seconds' => 120,
        'operator' => 'ops',
        'decision' => 'GO',
        'verification' => [
            'db_connectivity' => 'GO',
            'migration_consistency' => 'GO',
            'app_boot' => 'GO',
            'health_routes' => 'GO',
            'sample_readonly_queries' => 'GO',
            'pii_redaction_confirmed' => true,
        ],
    ], $overrides);
}

/** Write raw bytes to a unique temp evidence path and return it. */
function rdrsWriteRaw(string $body): string
{
    $dir = tempArtifactDir('rdrs1-', 0755);
    $path = $dir.'/latest.json';
    file_put_contents($path, $body);

    return $path;
}

/** A path inside an existing directory where no file was ever written. */
function rdrsAbsentPath(): string
{
    $dir = tempArtifactDir('rdrs1-', 0755);

    return $dir.'/latest.json';
}

/** Resolve the service with a reader that reports a fixed read state. */
function rdrsServiceWithReader(RestoreDrillEvidenceReader $reader): RestoreDrillEvidenceService
{
    app()->instance(RestoreDrillEvidenceReader::class, $reader);

    return app()->make(RestoreDrillEvidenceService::class);
}

/**
 * A reader whose read() fails the way a real I/O error does: the file is there,
 * the process may read it, and the read still returns nothing.
 *
 * This is a genuine `file_get_contents` outcome (it returns `false` on I/O
 * error, and on a file that vanishes or loses permission between the check and
 * the read). It is injected rather than provoked because a real disk fault is
 * not something a test can schedule — the state is real, only its timing is not
 * reproducible on demand.
 */
function rdrsFailingReader(string $state): RestoreDrillEvidenceReader
{
    return new class($state) extends RestoreDrillEvidenceReader
    {
        public function __construct(private readonly string $forced) {}

        public function read(string $absolutePath): array
        {
            return ['state' => $this->forced, 'contents' => null];
        }
    };
}

function rdrsIssues(array $result): array
{
    return (array) ($result['details']['issues'] ?? []);
}

// ---------------------------------------------------------------------------
// The reader itself — the boundary where a read outcome is decided.
// ---------------------------------------------------------------------------

it('never turns a failed read into empty content', function () {
    // The whole defect in one assertion: a failure state must not carry a
    // string, because a string is indistinguishable from a document.
    $reader = new RestoreDrillEvidenceReader;

    expect($reader->read(rdrsAbsentPath()))
        ->toBe(['state' => RestoreDrillEvidenceReader::READ_ABSENT, 'contents' => null]);
});

it('reports a successfully read empty file as empty, not as a failure', function () {
    $reader = new RestoreDrillEvidenceReader;
    $read = $reader->read(rdrsWriteRaw(''));

    // Zero bytes were genuinely read. That is a different fact from reading nothing.
    expect($read['state'])->toBe(RestoreDrillEvidenceReader::READ_EMPTY)
        ->and($read['contents'])->toBe('')
        ->and($read['state'])->not->toBe(RestoreDrillEvidenceReader::READ_FAILED);
});

it('reports content only when the read actually succeeded', function () {
    $read = (new RestoreDrillEvidenceReader)->read(rdrsWriteRaw('{"a":1}'));

    expect($read['state'])->toBe(RestoreDrillEvidenceReader::READ_OK)
        ->and($read['contents'])->toBe('{"a":1}');
});

// ---------------------------------------------------------------------------
// Negative controls — the states that used to be indistinguishable.
// ---------------------------------------------------------------------------

it('reports an absent evidence file as absent, never as invalid JSON', function () {
    $result = app(RestoreDrillEvidenceService::class)->evaluate(rdrsAbsentPath());

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_ABSENT)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_ABSENT);
});

it('reports an existing but empty evidence file as empty, not as a missing file', function () {
    $path = rdrsWriteRaw('');
    $result = app(RestoreDrillEvidenceService::class)->evaluate($path);

    // The operator has a file to go and look at; saying "no evidence file" sends
    // them looking for something that is already on disk.
    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_EMPTY)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_ABSENT)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_EMPTY)
        ->and($result['details']['evidence_file'])->toBe(basename($path));
});

it('reports an unreadable evidence file as unreadable, never as invalid JSON', function () {
    $path = rdrsWriteRaw(json_encode(rdrsValidPayload()));
    chmod($path, 0000);

    // A privileged process can read a 0000 file, so the real-filesystem proof is
    // only meaningful when the running user genuinely cannot. The deterministic
    // seam below covers the same state unconditionally.
    if (is_readable($path)) {
        chmod($path, 0644);
        $this->markTestSkipped('running as a user that can read a 0000 file; covered by the injected-reader control');
    }

    $result = app(RestoreDrillEvidenceService::class)->evaluate($path);
    chmod($path, 0644);

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_UNREADABLE)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_UNREADABLE);
});

it('reports an unreadable evidence file as unreadable via the injected reader', function () {
    $result = rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_UNREADABLE))
        ->evaluate(rdrsWriteRaw(json_encode(rdrsValidPayload())));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_UNREADABLE)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON);
});

it('reports a failed read as a failed read, never as invalid JSON', function () {
    $result = rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_FAILED))
        ->evaluate(rdrsWriteRaw(json_encode(rdrsValidPayload())));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_READ_FAILED)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_FAILED)
        // The remediation must point at storage, not at the document's format.
        ->and($result['remediation'])->not->toContain('JSON');
});

it('fails closed when a reader claims success but returns no bytes', function () {
    // Success without content is not content. Casting that absence to a string
    // is the original defect, so it must be refused at the boundary instead.
    $liar = new class extends RestoreDrillEvidenceReader
    {
        public function read(string $absolutePath): array
        {
            return ['state' => RestoreDrillEvidenceReader::READ_OK, 'contents' => null];
        }
    };

    $result = rdrsServiceWithReader($liar)->evaluate(rdrsWriteRaw('{}'));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_READ_FAILED)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON);
});

it('fails closed on a read state it does not recognise', function () {
    $result = rdrsServiceWithReader(rdrsFailingReader('some_future_state'))
        ->evaluate(rdrsWriteRaw(json_encode(rdrsValidPayload())));

    // An unknown read state must never fall through to "assume empty content".
    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON);
});

// ---------------------------------------------------------------------------
// Decode states — invalid JSON stays real, and stays narrow.
// ---------------------------------------------------------------------------

it('still reports genuinely malformed JSON as invalid JSON', function () {
    $result = app(RestoreDrillEvidenceService::class)->evaluate(rdrsWriteRaw('{"drill_id": "x", '));

    // Bytes were read and the decoder rejected them: this is the one case the
    // "invalid JSON" reason is actually about.
    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_OK);
});

it('does not call well-formed JSON unparseable just because it is not an evidence object', function (string $body) {
    $result = app(RestoreDrillEvidenceService::class)->evaluate(rdrsWriteRaw($body));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->toContain(RestoreDrillEvidenceService::ISSUE_NOT_AN_OBJECT)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON);
})->with(['12345', '"a string"', 'null', 'true']);

it('separates a structurally valid document with a missing field from a decode failure', function () {
    $payload = rdrsValidPayload();
    unset($payload['restore_target']);

    $result = app(RestoreDrillEvidenceService::class)->evaluate(rdrsWriteRaw((string) json_encode($payload)));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::FAIL)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_READ_FAILED)
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_OK);
});

// ---------------------------------------------------------------------------
// Trust before freshness — preserved from RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1.
// ---------------------------------------------------------------------------

it('keeps an unfaithful timestamp a timestamp problem, not a read or JSON problem', function () {
    $result = app(RestoreDrillEvidenceService::class)
        ->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['completed_at' => '2026-13-45T99:99:99Z']))));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and(rdrsIssues($result))->toContain('evidence_timestamp_'.RestoreDrillEvidenceService::TS_UNPARSEABLE)
        ->and(rdrsIssues($result))->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
        // Untrusted instant => no age at all. An unageable drill is never fresh.
        ->and($result['details']['age_hours'])->toBeNull();
});

it('keeps trusted-but-old evidence stale rather than invalid', function () {
    $old = gmdate('Y-m-d\TH:i:s\Z', time() - 800 * 3600);
    $result = app(RestoreDrillEvidenceService::class)
        ->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['started_at' => $old, 'completed_at' => $old]))));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::WATCH)
        ->and(rdrsIssues($result))->toContain('evidence_stale')
        ->and($result['details']['stale'])->toBeTrue()
        ->and($result['details']['timestamp_status'])->toBe(RestoreDrillEvidenceService::TS_VALID);
});

// ---------------------------------------------------------------------------
// Recoverability — the fix must not make legitimate evidence permanently WATCH.
// ---------------------------------------------------------------------------

it('still reaches GO for valid, fresh, safe staging evidence', function () {
    $result = app(RestoreDrillEvidenceService::class)
        ->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload())));

    expect($result['status'])->toBe(RestoreDrillEvidenceService::GO)
        ->and(rdrsIssues($result))->toBe([])
        ->and($result['details']['read_state'])->toBe(RestoreDrillEvidenceReader::READ_OK);
});

it('recovers to GO once an unreadable evidence file becomes readable again', function () {
    $path = rdrsWriteRaw((string) json_encode(rdrsValidPayload()));
    chmod($path, 0000);

    if (is_readable($path)) {
        chmod($path, 0644);
        $this->markTestSkipped('running as a user that can read a 0000 file');
    }

    expect(app(RestoreDrillEvidenceService::class)->evaluate($path)['status'])
        ->toBe(RestoreDrillEvidenceService::FAIL);

    chmod($path, 0644);

    // The read fault was transient; the evidence itself was always valid.
    expect(app(RestoreDrillEvidenceService::class)->evaluate($path)['status'])
        ->toBe(RestoreDrillEvidenceService::GO);
});

// ---------------------------------------------------------------------------
// Invariants that must hold across every state.
// ---------------------------------------------------------------------------

it('never returns GO for any untrusted evidence state', function () {
    $svc = app(RestoreDrillEvidenceService::class);

    $states = [
        'absent' => $svc->evaluate(rdrsAbsentPath()),
        'empty' => $svc->evaluate(rdrsWriteRaw('')),
        'whitespace' => $svc->evaluate(rdrsWriteRaw("   \n\t ")),
        'invalid_json' => $svc->evaluate(rdrsWriteRaw('{"a": ')),
        'not_an_object' => $svc->evaluate(rdrsWriteRaw('12345')),
        'invalid_schema' => $svc->evaluate(rdrsWriteRaw('{"schema_version":1}')),
        'bad_timestamp' => $svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['completed_at' => 'not-a-time'])))),
        'future_timestamp' => $svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['completed_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)])))),
        'stale' => $svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['completed_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 800 * 3600)])))),
        'production_overwrite' => $svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['production_overwrite' => true])))),
        'production_env' => $svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload(['environment' => 'production'])))),
        'unreadable' => rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_UNREADABLE))->evaluate(rdrsWriteRaw('{}')),
        'read_failed' => rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_FAILED))->evaluate(rdrsWriteRaw('{}')),
    ];

    foreach ($states as $label => $result) {
        expect($result['status'])->not->toBe(RestoreDrillEvidenceService::GO, "state {$label} must never be GO");
        expect($result['decision'])->not->toBe(RestoreDrillEvidenceService::GO, "decision {$label} must never be GO");
    }
});

it('reports exactly one read state per evaluation and never a contradictory pair', function () {
    $svc = app(RestoreDrillEvidenceService::class);

    $cases = [
        [$svc->evaluate(rdrsAbsentPath()), RestoreDrillEvidenceReader::READ_ABSENT],
        [$svc->evaluate(rdrsWriteRaw('')), RestoreDrillEvidenceReader::READ_EMPTY],
        [$svc->evaluate(rdrsWriteRaw('{"a": ')), RestoreDrillEvidenceReader::READ_OK],
        [$svc->evaluate(rdrsWriteRaw((string) json_encode(rdrsValidPayload()))), RestoreDrillEvidenceReader::READ_OK],
        [rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_FAILED))->evaluate(rdrsWriteRaw('{}')), RestoreDrillEvidenceReader::READ_FAILED],
    ];

    foreach ($cases as [$result, $expected]) {
        $state = $result['details']['read_state'] ?? null;
        expect($state)->toBe($expected);

        // A successful read can never carry a read-failure reason, and a failed
        // read can never carry a decode reason.
        $issues = rdrsIssues($result);
        if ($state === RestoreDrillEvidenceReader::READ_OK) {
            expect($issues)->not->toContain(RestoreDrillEvidenceService::ISSUE_READ_FAILED)
                ->and($issues)->not->toContain(RestoreDrillEvidenceService::ISSUE_UNREADABLE);
        } else {
            expect($issues)->not->toContain(RestoreDrillEvidenceService::ISSUE_INVALID_JSON)
                ->and($issues)->not->toContain(RestoreDrillEvidenceService::ISSUE_NOT_AN_OBJECT);
        }
    }
});

it('does not disclose the evidence directory path in a read-failure reason', function () {
    $path = rdrsWriteRaw('{}');
    $result = rdrsServiceWithReader(rdrsFailingReader(RestoreDrillEvidenceReader::READ_UNREADABLE))->evaluate($path);

    // Only the basename is ever surfaced; the absolute location is not operator-facing.
    $rendered = json_encode($result);
    expect($rendered)->not->toContain(dirname($path))
        ->and($result['details']['evidence_file'])->toBe(basename($path));
});

// ---------------------------------------------------------------------------
// Downstream — the rollout signal carries the truthful state, still non-GO.
// ---------------------------------------------------------------------------

it('surfaces the truthful read state on the rollout readiness signal', function () {
    $path = rdrsWriteRaw('');
    config()->set('rollout_readiness.paths.restore_drill_evidence', [$path]);

    $signal = collect(app(FiveBranchRolloutReadinessService::class)->collect()['signals'])
        ->firstWhere('key', 'restore_drill_evidence');

    expect($signal)->not->toBeNull()
        ->and($signal['status'])->not->toBe(RestoreDrillEvidenceService::GO)
        ->and((array) ($signal['details']['issues'] ?? []))->toContain(RestoreDrillEvidenceService::ISSUE_EMPTY);
});

it('does not let an unreadable evidence file clear the rollout readiness signal', function () {
    $path = rdrsWriteRaw((string) json_encode(rdrsValidPayload()));
    config()->set('rollout_readiness.paths.restore_drill_evidence', [$path]);
    app()->instance(RestoreDrillEvidenceReader::class, rdrsFailingReader(RestoreDrillEvidenceReader::READ_UNREADABLE));

    $signal = collect(app(FiveBranchRolloutReadinessService::class)->collect()['signals'])
        ->firstWhere('key', 'restore_drill_evidence');

    expect($signal)->not->toBeNull()
        ->and($signal['status'])->toBe(RestoreDrillEvidenceService::FAIL);
});
