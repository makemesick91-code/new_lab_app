<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\DoctorAccess\Services\DoctorBranchLockBulkAssignmentService;
use App\Modules\DoctorAccess\Support\DoctorBranchLockBulkAssignmentOutcome as Outcome;
use Illuminate\Console\Command;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — drive an owner-approved home-branch
 * matrix through the canonical approval workflow.
 *
 * DRY RUN IS THE DEFAULT AND WRITES NOTHING AT ALL. `--apply` additionally
 * requires `--confirm-plan=<digest>` — a hash of the exact matrix AND the
 * preconditions it was classified against, so consent binds to the delta rather
 * than to a moment, and estate drift between the preview and the write fails
 * closed. Deliberately a digest and not a prompt: a prompt auto-answers under
 * SSH, a deploy script or CI, which is precisely where this will be run.
 *
 * MAKER AND CHECKER ARE BOTH REQUIRED AND MUST DIFFER. The approval service
 * enforces that comparison too, in its transaction; it is asserted here as well
 * so the operator is told before anything is filed rather than after fourteen
 * requests exist. Shell access is not business authorization: each is checked
 * for the permission its half of the workflow actually needs.
 *
 * IT NEVER INFERS A BRANCH. The matrix is supplied whole — as `doctor_id=CODE`
 * pairs or a JSON file — and an entry the tool cannot read is REFUSED, never
 * guessed and never silently dropped. There is no "no filter means everyone".
 */
final class DoctorBranchLockBulkAssignCommand extends Command
{
    protected $signature = 'doctor:branch-lock-bulk-assign
        {--maker= : Id or email of the user FILING each request (required)}
        {--checker= : Id or email of the user APPROVING each request (required; must differ from maker)}
        {--matrix= : The approved matrix as doctor_id=BRANCH_CODE pairs, comma separated}
        {--matrix-file= : Path to a JSON object of {"doctor_id": "BRANCH_CODE"} (alternative to --matrix)}
        {--reason= : Why the fleet is being assigned (required to write, recorded on every audit row)}
        {--acknowledge-online= : Doctor ids whose session may be ended while they are working, comma separated}
        {--apply : Perform the writes; without it nothing is written}
        {--confirm-plan= : The plan digest printed by the dry run; required with --apply}
        {--dry-run : Explicit dry run (already the default; refuses when combined with --apply)}
        {--json : Emit the plan or the outcome as JSON}';

    protected $description = 'Assign owner-approved HOME branches through the canonical approval workflow, one doctor at a time (dry-run unless --apply --confirm-plan=<digest>). Never infers a branch, never performs a transfer, never touches a flag.';

    /** Files a request. The screen requires exactly this of the same action. */
    private const PERMISSION_MAKER = 'manage_doctor_branch_locks';

    /** Decides one. Held by Supervisor RME and deliberately not by the filer. */
    private const PERMISSION_CHECKER = 'approve_doctor_branch_locks';

    public function handle(DoctorBranchLockBulkAssignmentService $bulk): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('dry-run')) {
            return $this->refuse('--dry-run dan --apply tidak dapat digabungkan. Hilangkan salah satunya.');
        }

        $matrix = $this->resolveMatrix();

        if (is_string($matrix)) {
            return $this->refuse($matrix);
        }

        if ($matrix === []) {
            // An empty matrix is a refusal, never "everyone". A tool that treats
            // a missing selector as the whole fleet is one typo from assigning
            // every doctor in the estate.
            return $this->refuse('Matriks kosong. Sebutkan --matrix atau --matrix-file secara eksplisit; tidak ada perilaku "semua dokter".');
        }

        $maker = $this->resolveUser('maker', self::PERMISSION_MAKER);

        if (is_string($maker)) {
            return $this->refuse($maker);
        }

        $checker = $this->resolveUser('checker', self::PERMISSION_CHECKER);

        if (is_string($checker)) {
            return $this->refuse($checker);
        }

        if ((int) $maker->id === (int) $checker->id) {
            return $this->refuse('Maker dan checker tidak boleh pengguna yang sama. Pemisahan itu adalah kontrolnya, bukan formalitas.');
        }

        $plan = $bulk->plan($matrix);

        if (! $apply) {
            $this->renderPlan($plan);

            // A refused row is a refused row in a dry run too: the operator must
            // not read exit 0 as "this matrix is clean".
            return ($plan['summary'][Outcome::REFUSED] ?? 0) > 0 ? 1 : 0;
        }

        $confirm = (string) ($this->option('confirm-plan') ?? '');

        if ($confirm === '') {
            return $this->refuse('--apply memerlukan --confirm-plan='.$plan['digest'].' (jalankan dry run lebih dulu dan baca deltanya).');
        }

        if (! hash_equals($plan['digest'], $confirm)) {
            return $this->refuse(
                'Rencana sudah basi. Digest saat ini '.$plan['digest'].', yang dikonfirmasi '.$confirm.'. '
                .'Keadaan produksi berubah sejak dry run; ulangi dry run dan baca delta yang baru.'
            );
        }

        $reason = trim((string) ($this->option('reason') ?? ''));

        if ($reason === '') {
            return $this->refuse('--reason wajib saat menulis: alasannya tercatat pada setiap baris audit.');
        }

        $acknowledged = $this->idList('acknowledge-online');

        if (is_string($acknowledged)) {
            return $this->refuse($acknowledged);
        }

        $outcome = $bulk->apply($plan, $maker, $checker, $reason, $acknowledged);

        $this->renderOutcome($outcome);

        $failed = ($outcome['summary'][Outcome::FAILED] ?? 0)
            + ($outcome['summary'][Outcome::REFUSED] ?? 0)
            + ($outcome['summary'][Outcome::ONLINE_NEEDS_ACKNOWLEDGEMENT] ?? 0)
            + ($outcome['summary'][Outcome::TRANSFER_REQUIRED] ?? 0);

        return $failed > 0 ? 1 : 0;
    }

    /**
     * A LIST OF PAIRS, never a map keyed by doctor id.
     *
     * `17=SPN4,17=LDK2` must survive parsing as two entries so the planner can
     * REFUSE the contradiction. Keyed by doctor id it would silently collapse
     * to whichever came last and the tool would assign a branch nobody asked
     * for while reporting success.
     *
     * @return list<array{0:mixed,1:mixed}>|string the matrix, or a refusal message
     */
    private function resolveMatrix(): array|string
    {
        $inline = (string) ($this->option('matrix') ?? '');
        $file = (string) ($this->option('matrix-file') ?? '');

        if ($inline !== '' && $file !== '') {
            return '--matrix dan --matrix-file tidak dapat digabungkan. Pilih satu sumber matriks.';
        }

        if ($file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                return 'Berkas matriks tidak terbaca: '.$file;
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            if (! is_array($decoded)) {
                return 'Berkas matriks bukan objek JSON yang sah: '.$file;
            }

            $matrix = [];

            foreach ($decoded as $doctorId => $branchCode) {
                // Keys arrive as strings from JSON. One that is not a clean
                // integer is preserved AS IS so the planner refuses it by name,
                // rather than being cast to 0 and disappearing. A JSON object
                // cannot carry a duplicate key at all, so the pair list from
                // this branch is duplicate-free by construction.
                $matrix[] = [is_string($doctorId) && ctype_digit($doctorId) ? (int) $doctorId : $doctorId, $branchCode];
            }

            return $matrix;
        }

        if ($inline === '') {
            return [];
        }

        $matrix = [];

        foreach (explode(',', $inline) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            if (! str_contains($pair, '=')) {
                return 'Entri matriks tidak berbentuk doctor_id=KODE_CABANG: '.$pair;
            }

            [$doctorId, $branchCode] = explode('=', $pair, 2);
            $doctorId = trim($doctorId);

            $matrix[] = [ctype_digit($doctorId) ? (int) $doctorId : $doctorId, trim($branchCode)];
        }

        return $matrix;
    }

    /**
     * @return User|string the actor, or a refusal message
     */
    private function resolveUser(string $option, string $permission): User|string
    {
        $raw = trim((string) ($this->option($option) ?? ''));

        if ($raw === '') {
            return '--'.$option.' wajib. Maker dan checker harus disebut eksplisit.';
        }

        $user = ctype_digit($raw)
            ? User::query()->whereKey((int) $raw)->first()
            : User::query()->where('email', $raw)->first();

        if (! $user instanceof User) {
            return 'Pengguna --'.$option.'='.$raw.' tidak ditemukan.';
        }

        if ((bool) $user->is_active !== true) {
            return 'Pengguna --'.$option.'='.$raw.' tidak aktif.';
        }

        /*
         * EFFECTIVE permission, asked of the Gate. A direct-permission query
         * would miss a role grant and would also miss the single global Super
         * Admin bypass, and an audit that misses both is an audit that says the
         * wrong thing about the one actor most likely to be both parties.
         */
        if (! $user->can($permission)) {
            return 'Pengguna --'.$option.'='.$raw.' tidak memiliki izin '.$permission.'. Akses shell bukan otorisasi bisnis.';
        }

        return $user;
    }

    /**
     * @return list<int>|string
     */
    private function idList(string $option): array|string
    {
        $raw = trim((string) ($this->option($option) ?? ''));

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);

            if (! ctype_digit($candidate)) {
                return '--'.$option.' berisi id yang tidak sah: '.$candidate;
            }

            $ids[] = (int) $candidate;
        }

        return $ids;
    }

    /**
     * @param  array{rows:list<array<string,mixed>>,summary:array<string,int>,digest:string}  $plan
     */
    private function renderPlan(array $plan): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('DRY RUN — nothing has been written.');
        $this->newLine();
        $this->renderRows($plan['rows']);
        $this->renderSummary($plan['summary']);
        $this->newLine();
        $this->line('PLAN_DIGEST='.$plan['digest']);
        $this->comment('To apply: re-run with --apply --confirm-plan='.$plan['digest'].' --reason="..."');
    }

    /**
     * @param  array{rows:list<array<string,mixed>>,summary:array<string,int>}  $outcome
     */
    private function renderOutcome(array $outcome): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('APPLIED.');
        $this->newLine();
        $this->renderRows($outcome['rows']);
        $this->renderSummary($outcome['summary']);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function renderRows(array $rows): void
    {
        $this->table(
            ['Doctor', 'Id', 'Branch', 'Current', 'Online', 'Classification', 'Reason'],
            array_map(static fn (array $row): array => [
                (string) ($row['doctor_name'] ?? '?'),
                (string) ($row['doctor_id'] ?? '?'),
                (string) ($row['branch_code'] ?? '?'),
                $row['current_home_branch_id'] === null ? 'UNSET' : (string) $row['current_home_branch_id'],
                $row['online'] ? 'YES' : '-',
                (string) $row['classification'],
                (string) ($row['refusal_reason'] ?? '-'),
            ], $rows),
        );
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function renderSummary(array $summary): void
    {
        foreach ($summary as $key => $count) {
            $this->line($key.'='.$count);
        }
    }

    private function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line((string) json_encode(['refused' => true, 'message' => $message]))
            : $this->error($message);

        return 1;
    }
}
