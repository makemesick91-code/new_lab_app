<?php

namespace App\Console\Commands;

use App\Support\Database\DatabasePrivilegeCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 — read-only check of the database role
 * separation, run as the runtime login. Exit 0 = MATCH, 1 = DRIFT,
 * 2 = CANNOT VERIFY. See App\Support\Database\DatabasePrivilegeCheck.
 */
class FoundationDbPrivilegeCheckCommand extends Command
{
    protected $signature = 'foundation:db-privilege-check
        {--database= : The runtime connection to check (default: the default connection)}
        {--json : Print the result as JSON}';

    protected $description = 'Read-only check that the database matches the runtime role separation (R6-B3). Exit 0 match, 1 drift, 2 cannot verify.';

    private const VERDICTS = [
        DatabasePrivilegeCheck::MATCH => 'MATCH',
        DatabasePrivilegeCheck::DRIFT => 'DRIFT',
        DatabasePrivilegeCheck::CANNOT_VERIFY => 'CANNOT VERIFY',
    ];

    public function handle(DatabasePrivilegeCheck $check): int
    {
        $result = $check->run(
            DB::connection($this->option('database') ?: null),
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
        foreach ($result['problems'] as $problem) {
            $this->line(($result['status'] === DatabasePrivilegeCheck::CANNOT_VERIFY ? 'ERROR ' : 'FAIL  ').$problem);
        }
        $this->line("DB PRIVILEGE CHECK: {$verdict}");

        return $result['status'];
    }
}
