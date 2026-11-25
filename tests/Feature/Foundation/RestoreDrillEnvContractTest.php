<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 F3 — the Restore Environment Compatibility Contract (scripts/lib/restore-envcontract.sh): production's restore
 * environment, signed by the owner, bound to the role manifest, checked against the drill image's own properties and
 * pinned into every restore session. Synthetic, production-like profiles only (real ssh-keygen signatures).
 */

function rdecDir(): string
{
    $dir = sys_get_temp_dir().'/rdec-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'owner', '-f', "{$dir}/owner"]))->mustRun();
    (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'other', '-f', "{$dir}/other"]))->mustRun();
    file_put_contents("{$dir}/anchor", 'owner@dms '.file_get_contents("{$dir}/owner.pub"));

    return $dir;
}

/** A synthetic, production-like contract; $over replaces values (null removes the line); $extra adds raw lines. */
function rdecContract(string $dir, array $over = [], string $key = 'owner', string $namespace = 'dms-restore-env-contract', array $extra = []): string
{
    $fields = array_merge([
        'DMS_ENV_CONTRACT_VERSION' => '1',
        'DMS_ENV_CONTRACT_ID' => 'env-contract-0001',
        'DMS_ENV_CONTRACT_SOURCE' => 'synthetic-production',
        'DMS_ENV_CONTRACT_ISSUED_BY' => 'owner@dms',
        'DMS_ENV_CONTRACT_ISSUED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        'DMS_ENV_CONTRACT_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
        'DMS_ENV_PG_MAJOR' => '16',
        'DMS_ENV_PG_MINOR' => '15',
        'DMS_ENV_SERVER_ENCODING' => 'UTF8',
        'DMS_ENV_LOCALE_PROVIDER' => 'libc',
        'DMS_ENV_DATCOLLATE' => 'C.UTF-8',
        'DMS_ENV_DATCTYPE' => 'C.UTF-8',
        'DMS_ENV_ICU_LOCALE' => 'none',
        'DMS_ENV_COLLATION_VERSION' => 'none',
        'DMS_ENV_GLIBC_VERSION' => '2.39',
        'DMS_ENV_ICU_VERSION' => '74.2',
        'DMS_ENV_TZDATA_VERSION' => '2025b',
        'DMS_ENV_EXTENSIONS' => 'none',
        'DMS_ENV_SET_TIMEZONE' => 'Asia/Makassar',
        'DMS_ENV_SET_DATESTYLE' => 'ISO, MDY',
        'DMS_ENV_SET_INTERVALSTYLE' => 'postgres',
        'DMS_ENV_SET_ARRAY_NULLS' => 'on',
        'DMS_ENV_SET_LC_MONETARY' => 'C.UTF-8',
        'DMS_ENV_SET_LC_NUMERIC' => 'C.UTF-8',
        'DMS_ENV_SET_LC_TIME' => 'C.UTF-8',
        'DMS_ENV_SET_EXTRA_FLOAT_DIGITS' => '1',
        'DMS_ENV_SET_BYTEA_OUTPUT' => 'hex',
        'DMS_ENV_SET_XMLBINARY' => 'base64',
        'DMS_ENV_SET_XMLOPTION' => 'content',
        'DMS_ENV_SET_STANDARD_CONFORMING_STRINGS' => 'on',
        'DMS_ENV_SET_QUOTE_ALL_IDENTIFIERS' => 'off',
        'DMS_ENV_SET_DEFAULT_TEXT_SEARCH_CONFIG' => 'pg_catalog.english',
        'DMS_ENV_SET_TIMEZONE_ABBREVIATIONS' => 'Default',
        'DMS_ENV_SET_TRANSFORM_NULL_EQUALS' => 'off',
    ], $over);
    $file = "{$dir}/contract-".bin2hex(random_bytes(4));
    $lines = collect($fields)->filter(fn ($v) => $v !== null)->map(fn (string $v, string $k): string => "{$k}={$v}")->values()->all();
    file_put_contents($file, implode("\n", array_merge($lines, $extra))."\n");
    (new Process(['ssh-keygen', '-q', '-Y', 'sign', '-f', "{$dir}/{$key}", '-n', $namespace, $file]))->mustRun();

    return $file;
}

/** The drill image's properties, matching rdecContract() unless $over changes them. */
function rdecImage(string $dir, array $over = []): string
{
    $fields = array_merge([
        'DMS_IMAGE_SHA256' => str_repeat('a', 64),
        'DMS_IMAGE_PG_MAJOR' => '16',
        'DMS_IMAGE_PG_MINOR' => '15',
        'DMS_IMAGE_GLIBC_VERSION' => '2.39',
        'DMS_IMAGE_ICU_VERSION' => '74.2',
        'DMS_IMAGE_TZDATA_VERSION' => '2025b',
        'DMS_IMAGE_LOCALES' => 'C C.utf8 POSIX',
        'DMS_IMAGE_EXTENSIONS' => 'none',
    ], $over);
    $file = "{$dir}/image-".bin2hex(random_bytes(4)).'.properties';
    file_put_contents($file, collect($fields)->filter(fn ($v) => $v !== null)->map(fn (string $v, string $k): string => "{$k}={$v}")->implode("\n")."\n");

    return $file;
}

/**
 * Resolves `{now+N}` / `{now-N}` (seconds) when the test RUNS. A dataset is built when the suite is collected, and a
 * long sequential run (the critical gate) can execute it an hour later: a relative time must never be fixed there.
 */
function rdecNow(string $s): string
{
    return (string) preg_replace_callback('/\{now([+-])(\d+)\}/', fn (array $m) => gmdate('Y-m-d\TH:i:s\Z', $m[1] === '+' ? time() + (int) $m[2] : time() - (int) $m[2]), $s);
}

function rdecSh(string $script, array $env = []): array
{
    $p = new Process(['bash', '-c', '. "$LIB/restore-envcontract.sh"; '.$script], null, array_merge(['LIB' => base_path('scripts/lib'), 'PATH' => getenv('PATH')], $env));
    $p->run();

    return ['exit' => (int) $p->getExitCode(), 'out' => trim($p->getOutput().$p->getErrorOutput())];
}

it('verifies a contract the owner signed and prints its sha256', function () {
    $dir = rdecDir();
    $c = rdecContract($dir);
    $run = rdecSh('dms_env_contract_verify "$C" "$A" && echo "id=${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_ID]}"', ['C' => $c, 'A' => "{$dir}/anchor"]);
    $sha = hash_file('sha256', $c);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['out'])->toBe("{$sha}\nid=env-contract-0001");
});

it('refuses a contract that is unsigned, tampered, wrongly signed, out of date or malformed: production values never come from anyone else', function (string $case, string $message) {
    $dir = rdecDir();
    $c = match ($case) {
        'tampered after signing' => (function () use ($dir) {
            $f = rdecContract($dir);
            file_put_contents($f, str_replace('DMS_ENV_SET_ARRAY_NULLS=on', 'DMS_ENV_SET_ARRAY_NULLS=off', (string) file_get_contents($f)));

            return $f;
        })(),
        'signed by another key' => rdecContract($dir, [], 'other'),
        'signed for the role-manifest namespace' => rdecContract($dir, [], 'owner', 'dms-restore-role-manifest'),
        'no signature' => (function () use ($dir) {
            $f = rdecContract($dir);
            unlink("{$f}.sig");

            return $f;
        })(),
        'expired' => rdecContract($dir, ['DMS_ENV_CONTRACT_ISSUED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400), 'DMS_ENV_CONTRACT_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]),
        'issued in the future' => rdecContract($dir, ['DMS_ENV_CONTRACT_ISSUED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]),
        'valid for longer than 90 days' => rdecContract($dir, ['DMS_ENV_CONTRACT_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 100 * 86400)]),
        'a missing property' => rdecContract($dir, ['DMS_ENV_SET_ARRAY_NULLS' => null]),
        'an unknown property' => rdecContract($dir, [], 'owner', 'dms-restore-env-contract', ['DMS_ENV_SET_SEARCH_PATH=public']),
        'a property twice' => rdecContract($dir, [], 'owner', 'dms-restore-env-contract', ['DMS_ENV_SET_TIMEZONE=UTC']),
        'a malformed value' => rdecContract($dir, ['DMS_ENV_SET_DATESTYLE' => 'iso']),
        'a shell value' => rdecContract($dir, ['DMS_ENV_SET_TIMEZONE' => '$(reboot)']),
        'another contract version' => rdecContract($dir, ['DMS_ENV_CONTRACT_VERSION' => '2']),
        'an xmloption the archive overrides' => rdecContract($dir, ['DMS_ENV_SET_XMLOPTION' => 'document']),
        'standard_conforming_strings off' => rdecContract($dir, ['DMS_ENV_SET_STANDARD_CONFORMING_STRINGS' => 'off']),
    };
    $run = rdecSh('dms_env_contract_verify "$C" "$A"', ['C' => $c, 'A' => "{$dir}/anchor"]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(1, $run['out'])
        ->and($run['out'])->toContain($message);
})->with([
    ['tampered after signing', 'the signature does not verify'],
    ['signed by another key', 'the signature does not verify'],
    ['signed for the role-manifest namespace', 'in namespace dms-restore-env-contract'],
    ['no signature', 'is missing, a symlink or not a regular file'],
    ['expired', 'expired at'],
    ['issued in the future', 'issued in the future'],
    ['valid for longer than 90 days', 'longer than 90 days'],
    ['a missing property', 'DMS_ENV_SET_ARRAY_NULLS is missing'],
    ['an unknown property', 'unknown key DMS_ENV_SET_SEARCH_PATH'],
    ['a property twice', 'DMS_ENV_SET_TIMEZONE appears twice'],
    ['a malformed value', 'DMS_ENV_SET_DATESTYLE has an invalid value'],
    ['a shell value', 'DMS_ENV_SET_TIMEZONE has an invalid value'],
    ['another contract version', 'DMS_ENV_CONTRACT_VERSION has an invalid value'],
    // pg_restore's preamble sets both in every restore session, so no restore ever runs under another value.
    ['an xmloption the archive overrides', 'DMS_ENV_SET_XMLOPTION has an invalid value'],
    ['standard_conforming_strings off', 'DMS_ENV_SET_STANDARD_CONFORMING_STRINGS has an invalid value'],
]);

it('refuses a revoked contract, by id or by the not-before date of the revocation list', function (string $list, string $message) {
    $dir = rdecDir();
    $c = rdecContract($dir);
    file_put_contents("{$dir}/revocations", rdecNow($list));
    $run = rdecSh('dms_env_contract_verify "$C" "$A" > /dev/null && dms_env_contract_revocation_check "$R"', ['C' => $c, 'A' => "{$dir}/anchor", 'R' => "{$dir}/revocations"]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(1, $run['out'])->and($run['out'])->toContain($message);
})->with([
    'revoked by id' => ["# owner\nrevoked env-contract-0001\n", 'env-contract-0001 is revoked'],
    'issued before not-before' => ["not-before {now+3600}\n", 'was issued before'],
    'an unexpected line' => ["allow everything\n", 'unexpected line'],
]);

it('accepts a contract the drill image matches, and refuses every incompatibility before any VM boots (exact and compatible classes)', function (array $contract, array $image, string $allow, ?string $message) {
    $dir = rdecDir();
    $c = rdecContract($dir, $contract);
    $i = rdecImage($dir, $image);
    $run = rdecSh('dms_env_contract_verify "$C" "$A" > /dev/null && dms_env_image_properties_parse "$I" && dms_env_compatibility_check "$X" && echo COMPATIBLE', ['C' => $c, 'A' => "{$dir}/anchor", 'I' => $i, 'X' => $allow]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    if ($message === null) {
        expect($run['exit'])->toBe(0, $run['out'])->and($run['out'])->toBe('COMPATIBLE');
    } else {
        expect($run['exit'])->toBe(1, $run['out'])->and($run['out'])->toContain($message);
    }
})->with([
    'identical' => [[], [], 'none', null],
    'a newer image minor (compatible)' => [[], ['DMS_IMAGE_PG_MINOR' => '17'], 'none', null],
    'another PostgreSQL major' => [['DMS_ENV_PG_MAJOR' => '17'], [], 'none', 'PostgreSQL 17 in production, 16 in the drill image'],
    'an image below 16.15' => [[], ['DMS_IMAGE_PG_MINOR' => '9'], 'none', 'below 16.15'],
    'an image older than production\'s minor' => [['DMS_ENV_PG_MINOR' => '17'], ['DMS_IMAGE_PG_MINOR' => '16'], 'none', 'older 16.16 in the drill image'],
    'another glibc (collation)' => [['DMS_ENV_GLIBC_VERSION' => '2.35'], [], 'none', 'glibc 2.35 in production'],
    'another ICU' => [['DMS_ENV_ICU_VERSION' => '70.1'], [], 'none', 'ICU 70.1 in production'],
    'another tzdata' => [['DMS_ENV_TZDATA_VERSION' => '2024a'], [], 'none', 'tzdata 2024a in production'],
    'a locale the image lacks' => [['DMS_ENV_DATCOLLATE' => 'id_ID.UTF-8', 'DMS_ENV_DATCTYPE' => 'id_ID.UTF-8'], [], 'none', 'locale id_ID.UTF-8 is not available'],
    'a monetary locale the image lacks' => [['DMS_ENV_SET_LC_MONETARY' => 'id_ID.UTF-8'], [], 'none', 'locale id_ID.UTF-8 is not available'],
    'the locale present in the image' => [['DMS_ENV_DATCOLLATE' => 'en_US.UTF-8', 'DMS_ENV_DATCTYPE' => 'en_US.UTF-8', 'DMS_ENV_COLLATION_VERSION' => '2.39'], ['DMS_IMAGE_LOCALES' => 'C C.utf8 POSIX en_US.utf8'], 'none', null],
    // R6F-E13: the collation version a database recorded must be the one its locale has on the image's glibc.
    'a stale collation version (glibc upgraded under production)' => [['DMS_ENV_DATCOLLATE' => 'en_US.UTF-8', 'DMS_ENV_DATCTYPE' => 'en_US.UTF-8', 'DMS_ENV_COLLATION_VERSION' => '2.35'], ['DMS_IMAGE_LOCALES' => 'C C.utf8 POSIX en_US.utf8'], 'none', 'recorded collation version 2.35'],
    'a collation version on a C locale' => [['DMS_ENV_COLLATION_VERSION' => '2.39'], [], 'none', 'recorded collation version 2.39 is not the none'],
    'collate and ctype differ' => [['DMS_ENV_DATCTYPE' => 'C'], [], 'none', 'different collate and ctype'],
    'the ICU provider' => [['DMS_ENV_LOCALE_PROVIDER' => 'icu', 'DMS_ENV_ICU_LOCALE' => 'und'], [], 'none', 'only the libc locale provider'],
    'a non-UTF8 database' => [['DMS_ENV_SERVER_ENCODING' => 'LATIN1'], [], 'none', 'UTF8 databases only'],
    'an extension outside the allowlist' => [['DMS_ENV_EXTENSIONS' => 'pgcrypto:1.3'], ['DMS_IMAGE_EXTENSIONS' => 'pgcrypto:1.3'], 'none', 'outside the drill\'s allowlist'],
    'an extension at another version' => [['DMS_ENV_EXTENSIONS' => 'pgcrypto:1.2'], ['DMS_IMAGE_EXTENSIONS' => 'pgcrypto:1.3'], 'pgcrypto', 'not the version the drill image has'],
    'an allowlisted extension at its version' => [['DMS_ENV_EXTENSIONS' => 'pgcrypto:1.3'], ['DMS_IMAGE_EXTENSIONS' => 'pgcrypto:1.3'], 'pgcrypto', null],
]);

it('refuses image properties that are incomplete or malformed', function (array $over, string $message) {
    $dir = rdecDir();
    $run = rdecSh('dms_env_image_properties_parse "$I"', ['I' => rdecImage($dir, $over)]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(1)->and($run['out'])->toContain($message);
})->with([
    'no image digest' => [['DMS_IMAGE_SHA256' => null], 'DMS_IMAGE_SHA256 is missing'],
    'a malformed tzdata' => [['DMS_IMAGE_TZDATA_VERSION' => 'latest'], 'DMS_IMAGE_TZDATA_VERSION has an invalid value'],
]);

it('derives the pinned settings and the expected environment digest canonically, from the signed values only', function () {
    $dir = rdecDir();
    $c = rdecContract($dir);
    $run = rdecSh('dms_env_contract_verify "$C" "$A" > /dev/null && dms_env_pins && echo && dms_env_expected_digest', ['C' => $c, 'A' => "{$dir}/anchor"]);
    (new Process(['rm', '-rf', '--', $dir]))->run();
    $expected = hash('sha256', implode("\n", [
        'pg_major=16', 'server_encoding=UTF8', 'locale_provider=libc', 'datcollate=C.UTF-8', 'datctype=C.UTF-8', 'collation_version=none',
        'tzdata_version=2025b', 'TimeZone=Asia/Makassar', 'DateStyle=ISO, MDY', 'IntervalStyle=postgres', 'array_nulls=on', 'lc_monetary=C.UTF-8',
        'lc_numeric=C.UTF-8', 'lc_time=C.UTF-8', 'extra_float_digits=1', 'bytea_output=hex', 'xmlbinary=base64', 'xmloption=content',
        'standard_conforming_strings=on', 'quote_all_identifiers=off', 'default_text_search_config=pg_catalog.english', 'timezone_abbreviations=Default',
        'transform_null_equals=off',
    ])."\n");
    [$pins, $digest] = explode("\n", $run['out']);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($pins)->toBe('TimeZone=Asia/Makassar;DateStyle=ISO, MDY;IntervalStyle=postgres;array_nulls=on;lc_monetary=C.UTF-8;lc_numeric=C.UTF-8;lc_time=C.UTF-8;extra_float_digits=1;bytea_output=hex;xmlbinary=base64;xmloption=content;standard_conforming_strings=on;quote_all_identifiers=off;default_text_search_config=pg_catalog.english;timezone_abbreviations=Default;transform_null_equals=off')
        ->and($digest)->toBe($expected);
});

it('binds the contract into the drill: manifest contract 2 names it, the orchestrator requires it, the record carries it (F3)', function () {
    $orch = (string) file_get_contents(base_path('scripts/restore-drill-vm.sh'));
    $evidence = (string) file_get_contents(base_path('scripts/lib/restore-evidence.sh'));
    $inner = (string) file_get_contents(base_path('scripts/restore-drill-sandbox-inner.sh'));

    expect($orch)->toContain('[ -n "$ENV_CONTRACT" ] || refuse "--env-contract <signed Restore Environment Compatibility Contract> is required (F3)"')
        ->and($orch)->toContain('[ "$ENV_SHA" = "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ENV_CONTRACT_SHA256]}" ] || { ENV_SHA=""; refuse "the environment contract is not the one the signed manifest names"; }')
        ->and($orch)->toContain('why="$(dms_env_compatibility_check "$EXTENSIONS")" || { ENV_SHA=""; refuse "$why"; }')
        ->and($orch)->toContain('[env_pins]="$ENV_PINS"')
        ->and($orch)->toContain('"$EXPECT_ENV")"')
        ->and($orch)->toContain('DECISION="FAIL:image_properties_mismatch"')
        ->and($evidence)->toContain('env_contract=[0-9a-f]{64},env=[0-9a-f]{64}$')
        ->and($evidence)->toContain('a GO whose restore environment differs from the signed environment contract')
        // Every restore session runs with the pins; the guest reports what a pinned session observes.
        ->and($inner)->toContain('| PGOPTIONS="$PIN_OPTS" "$PGBIN/psql" -X -q -At -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -h "$R/data" -U "$RR" -d "$DB" -f - > "$R/replay.out" 2> "$R/replay.err" 3>&-')
        // F3 review: the digest is measured at the end of EVERY restore session too, not only in a fresh one.
        ->and($inner)->toContain('for e in "${SECTION_ENV[@]}"; do [ "$e" = "$envd" ] || envd=inconsistent; done')
        ->and($inner)->toContain('[ "${#SECTION_ENV[@]}" = 6 ] || envd=inconsistent')
        // R6F-E14: each session's marker carries a nonce drawn after the archive is fixed; the archive cannot forge it.
        ->and($inner)->toContain('nonce="$(printf \'%08x%08x%08x%08x\' "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM")"')
        ->and($inner)->toContain("\"SELECT pg_catalog.concat('DMSENV:\${nonce}:', \${ENV_EXPR}) \${ENV_FROM};\"")
        ->and($inner)->toContain('[[ "$l" =~ ^DMSENV:${nonce}:([0-9a-f]{64})$ ]] && env="${BASH_REMATCH[1]}"')
        // compared only after the last restore session (functions, triggers, ACLs included)
        ->and(strpos($inner, 'emit "env_digest=${envd}"'))->toBeGreaterThan(strpos($inner, 'restore_part acls "$R/list.acls"'))
        ->and($inner)->toContain('emit "env_digest=${envd}"')
        // The image properties must describe the image QEMU is pinned to, checked before boot.
        ->and($orch)->toContain('refuse "the image properties describe another image than the pinned drill image"')
        ->and($orch)->toContain('dd if="$ENV_CONTRACT" iflag=nofollow,nonblock');
});

it('keeps the production collection procedure read-only and separate: it only prints a draft for the owner to sign (F3)', function () {
    $script = (string) file_get_contents(base_path('scripts/restore-env-contract-collect.sh'));
    $sql = strtoupper($script);

    expect($script)->toContain('default_transaction_read_only=on')
        ->and($script)->toContain('UNSIGNED DRAFT')
        // stdout is exactly the draft (no comment lines), and the restore's own PGOPTIONS stay in effect.
        ->and($script)->toContain('PGOPTIONS="${PGOPTIONS:-} -c default_transaction_read_only=on"')
        ->and($script)->not->toMatch('/^echo "#/m')
        ->and($sql)->not->toMatch('/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|GRANT|REVOKE|COPY)\s/')
        ->and($script)->not->toContain('ssh-keygen -Y sign');
});
