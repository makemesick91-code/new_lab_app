<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * R6-B13 clinical asset backup and reconciliation (owner decision 2026-11-26 §8).
 *
 * scripts/clinical-asset-backup.sh keeps content-addressed, encrypted, hash-chained snapshots of the application's
 * clinical file trees; scripts/clinical-asset-reconcile.sh checks every database reference to a clinical file
 * (deploy/clinical-assets/asset-map.tsv) against a file tree. These tests run on synthetic files and throwaway
 * databases only. Production activation of the backup needs a separate owner approval.
 */

function cabMap(): array
{
    $rows = [];
    foreach (file(base_path('deploy/clinical-assets/asset-map.tsv')) as $line) {
        if (trim($line) === '' || str_starts_with($line, '#')) {
            continue;
        }
        $f = explode("\t", rtrim($line, "\n"));
        $rows[$f[0]] = ['class' => $f[1], 'unique' => $f[2], 'fallback' => $f[3], 'sql' => $f[4]];
    }

    return $rows;
}

it('backs up and reconciles synthetic clinical files end to end: missing, corrupted, orphaned, duplicate, interrupted, cross-branch, tampered (R6-B13)', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('needs PostgreSQL: the rehearsal creates throwaway databases');
    }
    $c = config('database.connections.pgsql');
    $work = sys_get_temp_dir().'/cab-'.bin2hex(random_bytes(6));
    $p = new Process(['bash', base_path('tests/Fixtures/clinical-assets/rehearsal.sh'), $work], null, [
        'PGHOST' => (string) $c['host'], 'PGPORT' => (string) $c['port'], 'PGUSER' => (string) $c['username'],
        'PGPASSWORD' => (string) ($c['password'] ?? ''), 'PGDATABASE' => (string) $c['database'], 'PATH' => getenv('PATH'),
    ]);
    $p->setTimeout(900);
    $p->run();
    $out = $p->getOutput();

    expect($p->getExitCode())->toBe(0, $out.$p->getErrorOutput())
        ->and($out)->toContain('TOTAL pass=33 fail=0')
        ->and(is_dir($work))->toBeFalse();
});

it('lists every private disk root of config/filesystems.php in the backup roots (R6-B13)', function () {
    $roots = [];
    foreach (file(base_path('deploy/clinical-assets/roots.conf')) as $line) {
        if (preg_match('/^([a-z][a-z0-9-]*)\s+(\S+)\s+(immutable|mutable)\s*$/', $line, $m)) {
            $roots[$m[2]] = $m[3];
        }
    }
    foreach (['local', 'public', 'clinical_evidence', 'legacy_rme_private', 'legacy_odontogram_private', 'legacy_mass_upload_private', 'rme_refund_settlement_evidence'] as $disk) {
        $root = (string) config("filesystems.disks.{$disk}.root");
        $rel = ltrim(substr($root, strlen(storage_path('app'))), '/');
        expect(str_starts_with($root, storage_path('app')))->toBeTrue($disk)
            ->and(array_key_exists($rel, $roots))->toBeTrue("{$disk} ({$rel}) is not in roots.conf");
    }
    expect($roots['clinical-evidence-private'])->toBe('immutable')
        ->and($roots['rme-refund-settlement-private'])->toBe('immutable');
});

it('runs every asset-map query against the migrated schema, read-only, returning disk, path, digest and branch (R6-B13)', function () {
    $map = cabMap();
    expect($map)->not->toBeEmpty();
    foreach ($map as $asset => $row) {
        expect($row['sql'])->toStartWith('SELECT ')
            ->and($row['sql'])->not->toMatch('/;|\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|COPY|GRANT)\b/i')
            ->and($row['class'])->toBeIn(['durable', 'transient'])
            ->and($row['unique'])->toBeIn(['0', '1']);
        $stmt = DB::getPdo()->query('SELECT * FROM ('.$row['sql'].') AS q LIMIT 0');
        expect($stmt->columnCount())->toBe(4, $asset);
    }
});

it('maps every file-path column of the migrated schema, or names why it holds no clinical file (R6-B13)', function () {
    // Columns that hold no application-managed clinical file today, each with its reason (agent inventory, R6-B13).
    $notFiles = [
        'trx_lab_orders.delivery_signature_path' => 'never written by any code',
        'trx_lab_orders.delivery_photo_path' => 'never written by any code',
        'trx_rme_payment_submissions.payment_path' => 'a payment workflow label (string 32), not a file',
        'mst_satusehat_code_mappings.target_path' => 'a path inside a SATUSEHAT payload, not a file',
        'trx_satusehat_data_quality_issues.field_path' => 'a path inside a SATUSEHAT payload, not a file',
    ];
    $sql = implode("\n", array_column(cabMap(), 'sql'));
    $cols = DB::select("SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = current_schema() AND column_name LIKE '%\\_path' ORDER BY 1, 2");
    $unmapped = [];
    foreach ($cols as $c) {
        $key = "{$c->table_name}.{$c->column_name}";
        if (isset($notFiles[$key])) {
            continue;
        }
        if (! preg_match('/\b'.preg_quote($c->table_name, '/').'\b/', $sql) || ! str_contains($sql, $c->column_name)) {
            $unmapped[] = $key;
        }
    }

    expect($cols)->not->toBeEmpty()
        ->and($unmapped)->toBe([]);
});

it('refuses a restore into the live storage and a non-empty target, and never follows a manifest path out of the target (R6-B13)', function () {
    $code = (string) file_get_contents(base_path('scripts/clinical-asset-backup.sh'));

    expect($code)->toContain('die "the target is inside the live storage: restores go to an isolated directory"')
        ->and($code)->toContain('die "the target must be a new or empty directory"')
        ->and($code)->toContain('[[ "/$2/" != */../* ]] && [[ "/$2/" != */./* ]] && [[ "$2" != /* ]] || tamper "a manifest path escapes the target"')
        ->and($code)->toContain("find \"\$STORAGE/\$rel\" -xdev -type f -printf '%P\\0'")
        ->and($code)->toContain('umask 077');
});
