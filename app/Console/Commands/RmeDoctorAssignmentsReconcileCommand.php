<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\RME\Services\DoctorAssignmentReconcileService;
use Illuminate\Console\Command;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.13, PR A4) — end
 * the automatic patient-doctor assignments that went stale before revocation
 * existed, and report what keeps the doctor encounter scope from being turned on.
 *
 * DRY RUN BY DEFAULT: it reads and prints counts — the stale `auto_visit` rows
 * (by cause), the stale open visits that keep a doctor's encounter window open
 * (rule 243 §2.10) and the roomless pre-exam canonical anchors (§2.11), with the
 * VISIT ids of those two reports — and the plan digest. Never an assignment id,
 * a name, a NIK, a Nomor RM or a visit number (CG-11).
 *
 * `--apply --confirm-plan=<digest>` ends exactly the stale rows of the reviewed
 * plan: the digest binds their ids, so a plan that drifted since the dry run is
 * refused and nothing is written. It sets `unassigned_at` only and never
 * deletes; a rerun plans nothing (idempotent). The two reports are never acted
 * on: closing a stale visit goes through the visit workflow (CG-13).
 *
 * Running `--apply` on production is a separate, owner-approved step after the
 * owner reviewed the dry run (CG-22), as the runtime user (never root).
 */
class RmeDoctorAssignmentsReconcileCommand extends Command
{
    protected $signature = 'rme:doctor-assignments-reconcile
        {--apply : End the stale auto_visit rows of the reviewed plan (default: dry run, nothing written)}
        {--confirm-plan= : The PLAN_DIGEST printed by the dry run; required with --apply}';

    protected $description = 'Report and end stale automatic doctor assignments (dry run unless --apply --confirm-plan=<digest>; sets unassigned_at only; counts and visit ids only).';

    public function handle(DoctorAssignmentReconcileService $reconcile): int
    {
        // Run as the runtime user (rule 195's pattern, as patient:rm-sequence-seed):
        // a root run leaves root-owned cache and log files. Fail closed when the
        // effective user cannot be determined.
        if (! function_exists('posix_geteuid')) {
            $this->error('Refusing to run: the effective user cannot be determined (the PHP posix extension is missing), so a root run cannot be ruled out. Run it as the runtime user with the posix extension enabled.');

            return self::FAILURE;
        }

        if (posix_geteuid() === 0) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan rme:doctor-assignments-reconcile`.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $plan = $reconcile->plan();

        if ($apply) {
            $confirm = trim((string) ($this->option('confirm-plan') ?? ''));

            if ($confirm === '') {
                $this->error('--apply needs --confirm-plan=<PLAN_DIGEST> from a reviewed dry run. Nothing was written.');

                return self::FAILURE;
            }

            if (! hash_equals($plan['digest'], $confirm)) {
                $this->line('PLAN_DIGEST_MISMATCH=yes');
                $this->error('The plan changed since the dry run that printed this digest. Run the dry run again and review it. Nothing was written.');

                return self::FAILURE;
            }
        }

        $this->renderPlan($plan);

        $result = $apply ? $reconcile->apply($plan) : ['ended' => 0, 'skipped' => 0];

        $this->line('ENDED='.$result['ended']);
        $this->line('SKIPPED='.$result['skipped']);
        $this->line('DOCTOR_ASSIGNMENTS_RECONCILE='.($apply ? 'APPLIED' : 'DRY_RUN'));

        if (! $apply) {
            $this->line('DRY RUN: nothing written. After the owner reviewed this plan: --apply --confirm-plan='.$plan['digest']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{today: string, digest: string, counts: array<string, int>, stale_open_visit_ids: list<int>, roomless_anchor_ids: list<int>}  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->line('CLINICAL_TODAY='.$plan['today']);

        foreach ($plan['counts'] as $name => $value) {
            $this->line(strtoupper($name).'='.$value);
        }

        $this->renderIds('STALE_OPEN_VISIT_IDS', $plan['stale_open_visit_ids']);
        $this->renderIds('ROOMLESS_PRE_EXAM_ANCHOR_IDS', $plan['roomless_anchor_ids']);
        $this->line('PLAN_DIGEST='.$plan['digest']);
    }

    /**
     * @param  list<int>  $ids
     */
    private function renderIds(string $key, array $ids): void
    {
        $shown = array_slice($ids, 0, DoctorAssignmentReconcileService::ID_LIST_LIMIT);

        $this->line($key.'='.implode(',', $shown));
        $this->line($key.'_TRUNCATED='.(count($ids) > count($shown) ? 'yes' : 'no'));
    }
}
