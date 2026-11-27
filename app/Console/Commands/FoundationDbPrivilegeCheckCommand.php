<?php

namespace App\Console\Commands;

use App\Support\Database\DatabasePrivilegeCheck;
use Illuminate\Console\Command;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 — read-only check of the database role
 * separation, run as the runtime login. Exit 0 = MATCH, 1 = DRIFT,
 * 2 = CANNOT VERIFY. See App\Support\Database\DatabasePrivilegeCheck.
 */
class FoundationDbPrivilegeCheckCommand extends Command
{
    protected $signature = 'foundation:db-privilege-check
        {--database= : The runtime connection to check (default: the default connection)}
        {--split-state : Only report, read-only, whether the role split is in place (split=1/0); exit 2 when it cannot tell}
        {--identity : Only print, read-only, a digest of the cluster system identifier and the database name; exit 2 when it cannot tell}
        {--json : Print the result as JSON}';

    protected $description = 'Read-only check that the database matches the runtime role separation (R6-B3). Exit 0 match, 1 drift, 2 cannot verify.';

    private const VERDICTS = [
        DatabasePrivilegeCheck::MATCH => 'MATCH',
        DatabasePrivilegeCheck::DRIFT => 'DRIFT',
        DatabasePrivilegeCheck::CANNOT_VERIFY => 'CANNOT VERIFY',
    ];

    public function handle(DatabasePrivilegeCheck $check): int
    {
        // R6-B4: the deploy refuses a migrator switch that disagrees with the database.
        if ($this->option('split-state')) {
            $state = $check->splitState($this->option('database') ?: null);
            $this->line($state === null ? 'split=unknown' : 'split='.($state ? '1' : '0'));

            return $state === null ? DatabasePrivilegeCheck::CANNOT_VERIFY : DatabasePrivilegeCheck::MATCH;
        }

        // R6-B4: the deploy proves the migration connection reaches the runtime's database (no value is printed).
        if ($this->option('identity')) {
            $identity = $check->identity($this->option('database') ?: null);
            $this->line('identity='.($identity ?? 'unknown'));

            return $identity === null ? DatabasePrivilegeCheck::CANNOT_VERIFY : DatabasePrivilegeCheck::MATCH;
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
