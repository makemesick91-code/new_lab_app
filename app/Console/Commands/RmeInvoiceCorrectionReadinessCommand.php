<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionReadinessService;
use Illuminate\Console\Command;

/**
 * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — read-only
 * activation readiness of the pre-payment invoice correction: DORMANT or
 * PILOT_ACTIVE and where, role grants against rule 195, named accounts' effective
 * capability inside the pilot, a maker/checker pair, active corrections (D10).
 *
 * This is the production AUTHORIZATION probe: it never logs in, never opens a
 * REPL, never writes a row (operator scope is resolved inside a rolled-back
 * transaction). Exit: FAIL → 1 always; WATCH → 1 only with --strict; GO → 0.
 */
class RmeInvoiceCorrectionReadinessCommand extends Command
{
    protected $signature = 'rme:invoice-correction-readiness
        {--user=* : Account id to report (repeatable)}
        {--maker= : Maker account id of the pilot pair}
        {--checker= : Checker account id of the pilot pair}
        {--json : Print the report as JSON}
        {--strict : Also fail (exit 1) on WATCH}';

    protected $description = 'Report pre-payment invoice correction activation readiness (rule 195): state, pilot scope, role grants, maker/checker pair. Read-only.';

    public function handle(RmeInvoiceCorrectionReadinessService $readiness): int
    {
        // Evaluating effective permissions can rebuild Spatie's permission cache
        // in the file store. Run as root, that leaves a root-owned cache file the
        // PHP-FPM runtime cannot overwrite — the FIX-LOGIN-REDIRECT-RUNTIME-
        // PERMISSIONS incident class (security review L4). Refuse, fail closed.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan rme:invoice-correction-readiness`.');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map(
            fn ($v) => ctype_digit((string) $v) ? (int) $v : null,
            (array) $this->option('user'),
        )));
        $maker = ctype_digit((string) $this->option('maker')) ? (int) $this->option('maker') : null;
        $checker = ctype_digit((string) $this->option('checker')) ? (int) $this->option('checker') : null;

        $report = $readiness->report($ids, $maker, $checker);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RME INVOICE CORRECTION — PRE-PAYMENT ACTIVATION (rule 195)');
            $this->line('STATE='.$report['state']);
            $this->line('FLAG_ENABLED='.($report['flag']['enabled'] ? 'true' : 'false').' env_resolution='.($report['flag']['env_resolution'] ?? 'n/a'));
            $this->line('PILOT_DECLARED='.implode(',', $report['pilot']['declared_codes']).' ADMITTED='.(implode(',', $report['pilot']['admitted_branch_codes']) ?: '(none)'));
            $this->line(sprintf(
                'PILOT_IDENTITIES valid=%s maker=%s checker=%s (owner D1e, rule 197)',
                $report['pilot_identities']['valid'] ? 'yes' : 'no',
                implode(',', $report['pilot_identities']['maker_user_ids']) ?: '(none)',
                implode(',', $report['pilot_identities']['checker_user_ids']) ?: '(none)',
            ));
            $this->line('APPLICATION='.$report['application_authority']);
            $this->line('POSITIVE_UNPAID_UNSETTLED_INVOICES_IN_PILOT='.$report['positive_unpaid_unsettled_invoices_in_pilot']);
            $this->newLine();

            $this->table(['Role', 'Correction permissions'], array_map(
                fn (string $role, array $perms) => [$role, $perms === [] ? '-' : implode(', ', $perms)],
                array_keys($report['roles']),
                array_values($report['roles']),
            ));

            if ($report['users'] !== []) {
                $this->table(
                    ['User', 'Roles', 'Pilot identity', 'Request', 'Approve', 'Apply(recovery)', 'Scope now', 'Pilot now', 'Pilot when on shift'],
                    array_map(fn (array $u) => ($u['exists'] ?? false) ? [
                        $u['id'],
                        implode(',', $u['roles']),
                        $u['designated_maker'] ? 'maker' : ($u['designated_checker'] ? 'checker' : 'none'),
                        $u['may_request_when_on_shift'] ? 'yes' : 'no',
                        $u['may_approve_when_on_shift'] ? 'yes' : 'no',
                        $u['can_apply_recovery'] ? 'yes' : 'no',
                        implode(',', $u['working_scope']) ?: '-',
                        implode(',', $u['pilot_branches_in_scope']) ?: '-',
                        implode(',', $u['eligible_pilot_branches']) ?: '-',
                    ] : [$u['id'], '(not found)', '-', '-', '-', '-', '-', '-', '-'], $report['users']),
                );
            }

            if ($report['maker_checker_pair'] !== null) {
                $p = $report['maker_checker_pair'];
                $this->line(sprintf(
                    'PAIR maker=%s checker=%s distinct=%s designated=%s maker_ok=%s checker_ok=%s shared=%s VALID=%s LIVE_NOW=%s',
                    $p['maker_id'] ?? '-',
                    $p['checker_id'] ?? '-',
                    $p['distinct_accounts'] ? 'yes' : 'no',
                    $p['maker_designated'] && $p['checker_designated'] ? 'yes' : 'no',
                    $p['maker_may_request_in_pilot'] ? 'yes' : 'no',
                    $p['checker_may_approve_in_pilot'] ? 'yes' : 'no',
                    implode(',', $p['shared_pilot_branches']) ?: '-',
                    $p['valid'] ? 'yes' : 'no',
                    $p['live_now'] ? 'yes' : 'no',
                ));
            }

            foreach ($report['active_corrections_by_branch'] as $code => $count) {
                $this->line(sprintf('ACTIVE_CORRECTIONS %s=%d', $code, $count));
            }

            foreach ($report['findings'] as $finding) {
                $this->line(sprintf('[%s] %s %s', $finding['level'], $finding['code'], $finding['detail']));
            }

            $this->newLine();
            $this->line('Decision: '.$report['decision']);
            $this->line('Note: readiness is not a pilot. A real correction needs an authorized case; none is fabricated.');
        }

        return match ($report['decision']) {
            RmeInvoiceCorrectionReadinessService::FAIL => self::FAILURE,
            RmeInvoiceCorrectionReadinessService::WATCH => $this->option('strict') ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }
}
