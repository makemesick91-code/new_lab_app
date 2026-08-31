<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorAccountLinkService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1 — governed doctor↔user mapping.
 *
 * The management UI is the normal way to link an account. This command exists
 * for the case the UI cannot serve: an operator working over SSH on the pilot,
 * where `tinker` is barred (psysh writes ERROR lines into the application log
 * and pins the monitor to WATCH for a day) and a raw SQL UPDATE would bypass the
 * eligibility guards and the audit trail that make linking safe.
 *
 * It is deliberately thin: every rule — active account, Doctor role already
 * held, one-to-one, explicit relink, audit row — lives in
 * DoctorAccountLinkService and is not restated here. Dry-run by default;
 * `--apply` is required to persist. It mirrors `lab:technician-link-user`.
 */
final class RmeDoctorLinkUserCommand extends Command
{
    protected $signature = 'rme:doctor-link-user
        {--doctor= : Doctor id or code}
        {--user= : User id or email}
        {--dry-run : Preview only (default)}
        {--apply : Persist the link}
        {--confirm-relink : Allow replacing an existing link}
        {--json}';

    protected $description = 'Link a master doctor to an existing Doctor-role user account (dry-run by default).';

    public function handle(DoctorAccountLinkService $links): int
    {
        $doctorRef = (string) ($this->option('doctor') ?? '');
        $userRef = (string) ($this->option('user') ?? '');

        if ($doctorRef === '' || $userRef === '') {
            $this->error('Both --doctor=<id|code> and --user=<id|email> are required.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        $doctor = $this->resolveDoctor($doctorRef);
        $user = $this->resolveUser($userRef);

        if ($doctor === null) {
            return $this->reportFailure("Doctor not found: {$doctorRef}");
        }

        if ($user === null) {
            return $this->reportFailure("User not found: {$userRef}");
        }

        $before = $doctor->user_id === null ? null : (int) $doctor->user_id;

        if (! $apply) {
            // Dry-run reports the intent and the current state; it never writes,
            // and it never pre-judges the service's eligibility verdict.
            $this->report([
                'mode' => 'dry-run',
                'doctor_id' => (int) $doctor->id,
                'user_id' => (int) $user->id,
                'current_linked_user_id' => $before,
                'would_change' => $before !== (int) $user->id,
                'note' => 'Nothing was written. Re-run with --apply to persist.',
            ]);

            return self::SUCCESS;
        }

        try {
            $updated = $links->link($doctor, (int) $user->id, (bool) $this->option('confirm-relink'));
        } catch (ValidationException $e) {
            return $this->reportFailure(implode(' ', $e->validator->errors()->all()));
        }

        $this->report([
            'mode' => 'applied',
            'doctor_id' => (int) $updated->id,
            'user_id' => $updated->user_id === null ? null : (int) $updated->user_id,
            'previous_linked_user_id' => $before,
            'audited' => true,
        ]);

        return self::SUCCESS;
    }

    private function resolveDoctor(string $ref): ?Doctor
    {
        return is_numeric($ref)
            ? Doctor::query()->find((int) $ref)
            : Doctor::query()->where('code', $ref)->first();
    }

    private function resolveUser(string $ref): ?User
    {
        return is_numeric($ref)
            ? User::query()->find((int) $ref)
            : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($ref)])->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->line(sprintf('%-26s %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '—')));
        }
    }

    private function reportFailure(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
