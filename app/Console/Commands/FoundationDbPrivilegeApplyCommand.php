<?php

namespace App\Console\Commands;

use App\Support\Database\DatabasePrivilegeCheck;
use App\Support\Database\MigrationConnection;
use App\Support\Database\RuntimeGrantPlan;
use App\Support\Database\RuntimePrivilegeRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 — the per-deploy runtime grants.
 *
 * - No option: a DRY RUN. It reads the catalog in a READ ONLY transaction and
 *   reports the plan, its SHA-256 and what --apply would change. Nothing is
 *   executed.
 * - --print-sql: prints the forward SQL; with --rollback, the SQL that restores
 *   the runtime's current grants exactly. Executes nothing.
 * - --apply: executes, in one transaction on `pgsql_migrate` as the owner role,
 *   the statements of every object whose runtime grants differ from its class;
 *   then re-checks the grants inside the same transaction and rolls back on any
 *   difference. It refuses on any other connection, and while any table or
 *   sequence is not owned by the owner role (provisioning, R6-D, comes first).
 *
 * Exit 0 on success; 1 on a refusal or a failed apply.
 */
class FoundationDbPrivilegeApplyCommand extends Command
{
    protected $signature = 'foundation:db-privilege-apply
        {--print-sql : Print the forward SQL and execute nothing}
        {--rollback : With --print-sql, print the SQL that restores the runtime\'s current grants}
        {--apply : Execute the grants on pgsql_migrate as the owner role, in one transaction}
        {--database= : The connection a dry run or --print-sql reads (default: the default connection)}
        {--json : Print the result as JSON}';

    protected $description = 'Per-deploy runtime grants from the privilege registry (R6-B3). Dry run by default; --print-sql; --apply on pgsql_migrate only.';

    public function handle(RuntimeGrantPlan $plan, RuntimePrivilegeRegistry $registry): int
    {
        if ($this->option('apply') && $this->option('print-sql')) {
            return $this->refuse('--apply and --print-sql exclude each other.');
        }
        if ($this->option('rollback') && ! $this->option('print-sql')) {
            return $this->refuse('--rollback only prints: use it with --print-sql. A rollback is run by the administrator with psql.');
        }
        $database = $this->option('database') ?: null;
        if ($this->option('apply') && $database !== null && $database !== MigrationConnection::NAME) {
            return $this->refuse('--apply runs only on '.MigrationConnection::NAME.'.');
        }

        $runtime = (string) config('runtime_privilege_classes.roles.runtime', '');
        $owner = (string) config('runtime_privilege_classes.roles.owner', '');
        foreach (['runtime' => $runtime, 'owner' => $owner] as $label => $role) {
            if (preg_match(RuntimeGrantPlan::IDENTIFIER, $role) !== 1) {
                return $this->refuse("the {$label} role is not configured as a plain role name.");
            }
        }
        if ($runtime === $owner) {
            return $this->refuse('the runtime role and the owner role must differ.');
        }

        try {
            if ($this->option('apply')) {
                return $this->apply($plan, DB::connection(MigrationConnection::NAME), $runtime, $owner);
            }

            return $this->readOnly($plan, DB::connection($database), $runtime, $owner);
        } catch (Throwable $exception) {
            return $this->refuse(self::safeMessage($exception));
        }
    }

    /**
     * A refusal's text. A database error carries the connection details and role
     * names, so only its class and SQLSTATE are shown (OD-R6-6); this command's
     * own refusals and the pgsql_migrate guard's carry no configured value.
     */
    private static function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof QueryException || $exception instanceof \PDOException) {
            // The driver code is not always the SQLSTATE (a failed connect reports 7);
            // the five-character state is read from the message, and nothing else of it.
            $state = preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $exception->getMessage(), $m) === 1 ? $m[1] : 'unknown';

            return 'the database refused a statement ('.class_basename($exception).', SQLSTATE '.$state.').';
        }
        if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'unexpected '.class_basename($exception).'.';
    }

    private function readOnly(RuntimeGrantPlan $plan, ConnectionInterface $connection, string $runtime, string $owner): int
    {
        $connection->beginTransaction();
        try {
            // Inside an outer transaction (a test's) the mode can no longer be set;
            // the dry run still only reads, and the savepoint is rolled back.
            if ($connection->transactionLevel() === 1) {
                $connection->statement('SET TRANSACTION READ ONLY');
            }
            [$entries, $sequences, $errors] = $plan->inputs($connection);
            if ($errors !== []) {
                return $this->refuse('the registry does not agree with the schema', $errors);
            }
            $forward = RuntimeGrantPlan::forwardStatements($entries, $sequences, $runtime);
            $current = RuntimeGrantPlan::currentGrants($connection, $runtime);

            if ($this->option('print-sql')) {
                $statements = $this->option('rollback')
                    ? RuntimeGrantPlan::rollbackStatements($entries, $sequences, $current, $runtime)
                    : $forward;
                $this->output->write(RuntimeGrantPlan::script($statements, $owner, $this->option('rollback') ? 'rollback' : 'forward'));

                return self::SUCCESS;
            }

            $changed = RuntimeGrantPlan::changedObjects(RuntimeGrantPlan::expectedGrants($entries, $sequences), $current);
            $foreign = $this->foreignOwned($connection, $owner);
            $classes = array_count_values(array_column($entries, 'class'));
            ksort($classes);
            $report = [
                'mode' => 'dry-run',
                'tables' => count($entries),
                'classes' => $classes,
                'sequences' => count($sequences),
                'statements' => count($forward),
                'forward_sha256' => hash('sha256', RuntimeGrantPlan::script($forward, $owner, 'forward')),
                'objects_to_change' => count($changed),
                'objects_not_owned_by_the_owner_role' => $foreign,
                'apply_would_refuse' => $foreign > 0,
            ];

            if ($this->option('json')) {
                $this->line((string) json_encode($report + ['changed' => $changed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $this->line('DRY RUN: nothing is executed.');
            foreach ($report as $key => $value) {
                $this->line(sprintf('  %s: %s', $key, is_array($value) ? json_encode($value) : var_export($value, true)));
            }
            foreach (array_slice($changed, 0, 50) as $name) {
                $this->line("  would change: {$name}");
            }

            return self::SUCCESS;
        } finally {
            $connection->rollBack();
        }
    }

    private function apply(RuntimeGrantPlan $plan, ConnectionInterface $connection, string $runtime, string $owner): int
    {
        // The pgsql_migrate guard has already asserted the session on connect.
        if ((string) $connection->selectOne('select current_user as u')->u !== $owner) {
            return $this->refuse('the session is not the owner role.');
        }
        $runtimeRole = $connection->selectOne('select rolsuper, rolcanlogin from pg_catalog.pg_roles where rolname = ?', [$runtime]);
        if ($runtimeRole === null || (bool) $runtimeRole->rolsuper) {
            return $this->refuse('the runtime role does not exist or is a superuser.');
        }

        try {
            $applied = $connection->transaction(function () use ($plan, $connection, $runtime, $owner): array {
                $connection->statement("SET LOCAL lock_timeout = '5s'");
                $connection->statement("SET LOCAL statement_timeout = '120s'");
                [$entries, $sequences, $errors] = $plan->inputs($connection);
                if ($errors !== []) {
                    throw new RuntimeException('the registry does not agree with the schema: '.implode('; ', array_slice($errors, 0, 5)));
                }
                if (($foreign = $this->foreignOwned($connection, $owner)) > 0) {
                    throw new RuntimeException("{$foreign} table(s) or sequence(s) in public are not owned by the owner role: provisioning (R6-D) comes first.");
                }

                $forward = RuntimeGrantPlan::forwardStatements($entries, $sequences, $runtime);
                $changed = RuntimeGrantPlan::changedObjects(RuntimeGrantPlan::expectedGrants($entries, $sequences), RuntimeGrantPlan::currentGrants($connection, $runtime));
                $statements = RuntimeGrantPlan::statementsFor($forward, $changed);
                foreach ($statements as $statement) {
                    $connection->statement($statement);
                }

                $problems = DatabasePrivilegeCheck::grantProblems($connection, $runtime, $entries);
                if ($problems !== []) {
                    throw new RuntimeException('after the grants the runtime still differs from its classes ('.count($problems).'): '.implode('; ', array_slice($problems, 0, 5)));
                }

                return ['objects' => count($changed), 'statements' => count($statements), 'forward_sha256' => hash('sha256', RuntimeGrantPlan::script($forward, $owner, 'forward'))];
            });
        } catch (Throwable $exception) {
            return $this->refuse('nothing was changed: '.self::safeMessage($exception));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['mode' => 'apply'] + $applied, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->line("APPLIED: {$applied['statements']} statement(s) for {$applied['objects']} object(s) in one transaction; forward_sha256 {$applied['forward_sha256']}.");

        return self::SUCCESS;
    }

    /** Tables and sequences in public whose owner is not the owner role. */
    private function foreignOwned(ConnectionInterface $connection, string $owner): int
    {
        return (int) $connection->selectOne(
            "select count(*) as n from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p', 'S') and pg_catalog.pg_get_userbyid(c.relowner) <> ?",
            [$owner]
        )->n;
    }

    /** @param  list<string>  $details */
    private function refuse(string $reason, array $details = []): int
    {
        $this->error("db-privilege-apply refused: {$reason}");
        foreach (array_slice($details, 0, 50) as $detail) {
            $this->line("  {$detail}");
        }

        return self::FAILURE;
    }
}
