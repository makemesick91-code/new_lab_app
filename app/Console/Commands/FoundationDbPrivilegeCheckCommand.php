<?php

namespace App\Console\Commands;

use App\Support\Database\DatabasePrivilegeCheck;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 — read-only check of the database role
 * separation, run as the runtime login. Exit 0 = MATCH, 1 = DRIFT,
 * 2 = CANNOT VERIFY. See App\Support\Database\DatabasePrivilegeCheck.
 *
 * R6-B3c: --print-baseline prints the PostgreSQL 16 baseline document of a
 * fresh database (tests/Fixtures/r6/pg16-runtime-baseline.txt is one), through
 * the same enumeration the check compares with. Connect it as a plain login that
 * holds exactly pg_read_all_settings and pg_read_all_stats WITH INHERIT TRUE, to
 * a fresh database of a fresh initdb cluster with the allowlisted extensions; it
 * refuses (exit 2) a database that is visibly not fresh.
 */
class FoundationDbPrivilegeCheckCommand extends Command
{
    protected $signature = 'foundation:db-privilege-check
        {--database= : The runtime connection to check (default: the default connection)}
        {--json : Print the result as JSON}
        {--print-baseline : Print the PostgreSQL 16 privilege baseline of a fresh database instead of checking (R6-B3c)}';

    protected $description = 'Read-only check that the database matches the runtime role separation (R6-B3). Exit 0 match, 1 drift, 2 cannot verify.';

    private const VERDICTS = [
        DatabasePrivilegeCheck::MATCH => 'MATCH',
        DatabasePrivilegeCheck::DRIFT => 'DRIFT',
        DatabasePrivilegeCheck::CANNOT_VERIFY => 'CANNOT VERIFY',
    ];

    public function handle(DatabasePrivilegeCheck $check): int
    {
        if ($this->option('print-baseline')) {
            $baseline = $check->baseline($this->option('database') ?: null);
            if ($baseline['text'] !== null) {
                // Raw bytes: the document is compared byte for byte with the committed fixture.
                $this->output->write($baseline['text'], false, OutputInterface::OUTPUT_RAW);

                return DatabasePrivilegeCheck::MATCH;
            }
            foreach ($baseline['problems'] as $problem) {
                $this->line("ERROR {$problem}");
            }
            $this->line('DB PRIVILEGE BASELINE: CANNOT GENERATE');

            return DatabasePrivilegeCheck::CANNOT_VERIFY;
        }

        // The connection is resolved inside the check, so a failure to resolve it
        // is CANNOT VERIFY (exit 2), never DRIFT (review round 1, finding 10).
        $result = $check->run(
            $this->option('database') ?: null,
            (string) config('runtime_privilege_classes.roles.owner', ''),
            (string) config('runtime_privilege_classes.roles.runtime', ''),
        );
        $verdict = self::VERDICTS[$result['status']];

        if ($this->option('json')) {
            $this->line((string) json_encode(['verdict' => $verdict] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'];
        }

        foreach ($result['passed'] as $passed) {
            $this->line("PASS  {$passed}");
        }
        foreach ($result['notes'] ?? [] as $note) {
            $this->line("NOTE  {$note}");
        }
        foreach ($result['problems'] as $problem) {
            $this->line(($result['status'] === DatabasePrivilegeCheck::CANNOT_VERIFY ? 'ERROR ' : 'FAIL  ').$problem);
        }
        $this->line("DB PRIVILEGE CHECK: {$verdict}");

        return $result['status'];
    }
}
