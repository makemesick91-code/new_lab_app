<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Services\PatientRmSequenceSeedService;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Console\Command;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — seed the automatic
 * Nomor RM counters from the numbers already issued.
 *
 * Dry run by default: reads the issued numbers and prints, per branch-year,
 * counts and the proposed counter, plus totals — never a Nomor RM and never a
 * patient detail. `--apply` raises each counter to GREATEST(current, proposed):
 * it never lowers a counter, so a rerun changes nothing, and it writes one audit
 * row of counts. Scope is the current clinic year (ClinicalClock) unless
 * `--all-years`; `--ignore-above=N` keeps unusually high numbers out of the
 * counter (they stay reserved: the allocator still skips them).
 *
 * The allocator also seeds a missing branch-year on first use, so correctness
 * never depends on this command; it exists so the owner can review the numbers
 * before the automatic number is switched on. Running `--apply` on production is
 * a separate, owner-approved step, as the runtime user (never root).
 */
class PatientRmSequenceSeedCommand extends Command
{
    protected $signature = 'patient:rm-sequence-seed
        {--apply : Raise the counters (default: dry run, nothing written)}
        {--all-years : Every registration year, not only the current clinic year}
        {--ignore-above= : Keep numeric sequences above this value out of the counter}';

    protected $description = 'Seed the automatic Nomor RM counters from issued numbers (dry run unless --apply; never lowers; counts only).';

    public const AUDIT_TABLE = 'mst_patient_rm_sequences';

    public const AUDIT_ACTION = 'PATIENT_RM_SEQUENCE_SEEDED';

    public function handle(PatientRmSequenceSeedService $seeds, ClinicalClock $clock, AuditLogService $audit): int
    {
        // Run as the runtime user: a root run would leave root-owned cache and log
        // files the application cannot write (rule 195 / rme:billing-dispute-status).
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan patient:rm-sequence-seed`.');

            return self::FAILURE;
        }

        $ignoreAbove = $this->option('ignore-above');

        if ($ignoreAbove !== null && preg_match('/^\d{1,9}$/', (string) $ignoreAbove) !== 1) {
            $this->error('--ignore-above must be a whole number.');

            return self::FAILURE;
        }

        $ignoreAbove = $ignoreAbove === null ? null : (int) $ignoreAbove;
        $onlyYear = $this->option('all-years') ? null : (int) $clock->today()->format('Y');
        $apply = (bool) $this->option('apply');

        $plan = $seeds->plan($onlyYear, $ignoreAbove);

        $this->line('SCOPE='.($onlyYear === null ? 'ALL_YEARS' : 'YEAR_'.$onlyYear));

        foreach ($plan['groups'] as $group) {
            $this->line(sprintf(
                'BRANCH=%s YEAR=%d NUMBERS=%d NON_NUMERIC=%d ABOVE_9999=%d IGNORED_ABOVE=%d PROPOSED_COUNTER=%d',
                $group['code'],
                $group['year'],
                $group['numbers'],
                $group['non_numeric'],
                $group['above_9999'],
                $group['ignored_above'],
                $group['proposed'],
            ));
        }

        foreach ($plan['totals'] as $name => $value) {
            $this->line(strtoupper($name).'='.$value);
        }

        $result = $apply ? $seeds->apply($plan['groups']) : ['created' => 0, 'raised' => 0, 'unchanged' => 0];

        foreach ($result as $name => $value) {
            $this->line(strtoupper($name).'='.$value);
        }

        if ($apply) {
            $audit->log(self::AUDIT_TABLE, null, self::AUDIT_ACTION, null, $result + [
                'scope' => $onlyYear === null ? 'all_years' : 'year',
                'year' => $onlyYear,
                'ignore_above' => $ignoreAbove,
                'groups' => $plan['totals']['groups'],
                'non_numeric' => $plan['totals']['non_numeric'],
                'above_9999' => $plan['totals']['above_9999'],
                'unknown_code_numbers' => $plan['totals']['unknown_code_numbers'],
            ]);
        }

        $this->line('PATIENT_RM_SEQUENCE_SEED='.($apply ? 'APPLIED' : 'DRY_RUN'));

        return self::SUCCESS;
    }
}
