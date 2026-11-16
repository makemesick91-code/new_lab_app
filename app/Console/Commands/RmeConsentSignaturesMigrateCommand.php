<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-9, rule 121 §6) — the COPY phase of the
 * consent signature storage migration.
 *
 * Consent signatures used to be written to the generic private `local` disk; they
 * now belong on the clinical evidence disk (ClinicalEvidenceStorage). Every
 * object a consent row references is copied there under the SAME key — no
 * database column is rewritten — and the copy is proven byte-identical by
 * SHA-256 before it counts. Nothing is ever deleted from the old disk: purging it
 * is a separate phase and a separate owner decision. An object already present
 * on the clinical disk with different bytes is a CONFLICT and is never
 * overwritten.
 *
 * Dry run by default (reads only, writes nothing, no audit row). `--apply`
 * copies and writes one summary audit row. The output is counts only: no object
 * key, consent number or patient detail is printed, and a storage failure is
 * reported by its exception class only (its message carries the object key).
 *
 * An object that exists but cannot be read is UNREADABLE: on the legacy side it
 * is never copied (no empty object is written), on the clinical side it is
 * never overwritten. A failed write is WRITE_FAILED. Either makes the run
 * INCOMPLETE (exit 1); the rest of the run continues.
 *
 * Runs as the runtime user `daengtisiams` only, and refuses root: the clinical
 * disk is private (directories 0700, files 0600), so objects or directories
 * created by another user are unreadable to PHP-FPM, and a root-owned
 * directory would make every later consent signing fail.
 */
class RmeConsentSignaturesMigrateCommand extends Command
{
    protected $signature = 'rme:consent-signatures-migrate {--apply : Copy the objects (default: dry run, nothing written)}';

    protected $description = 'Copy consent signature objects from the legacy private disk to the clinical evidence disk, key for key, SHA-256 verified (dry run unless --apply; never deletes).';

    public const AUDIT_TABLE = 'trx_rme_visit_consents';

    public const AUDIT_ACTION = 'RME_CONSENT_SIGNATURE_STORAGE_COPIED';

    private const ABSENT = 'absent';

    private const PRESENT = 'present';

    private const UNREADABLE = 'unreadable';

    public function handle(RmeVisitConsentRepositoryInterface $consents, AuditLogService $audit): int
    {
        if ($this->runningAsRoot()) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan rme:consent-signatures-migrate`.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $sourceDiskName = (string) config('rme_consent.legacy_signature_disk', 'local');
        $targetDiskName = ClinicalEvidenceStorage::diskName();

        if ($sourceDiskName === $targetDiskName) {
            $this->error('The legacy disk and the clinical evidence disk are the same; nothing to copy.');

            return self::FAILURE;
        }

        $source = Storage::disk($sourceDiskName);
        $target = ClinicalEvidenceStorage::disk();

        $counts = [
            'references' => 0,
            'inline' => 0,
            'already_copied' => 0,
            'to_copy' => 0,
            'copied' => 0,
            'missing' => 0,
            'conflict' => 0,
            'unreadable' => 0,
            'write_failed' => 0,
            'verify_failed' => 0,
        ];

        foreach ($consents->signatureReferences() as $row) {
            foreach ([$row->consenter_signature_path, $row->doctor_signature_path] as $key) {
                if ($key === null || $key === '') {
                    continue;
                }

                $counts['references']++;

                if (ClinicalEvidenceStorage::isInlineDataUri($key)) {
                    $counts['inline']++;

                    continue;
                }

                [$sourceState, $sourceBytes] = $this->read($source, $key);
                [$targetState, $targetBytes] = $this->read($target, $key);

                // An object on the clinical disk that cannot be read is never
                // overwritten: it may hold the only good copy.
                if ($targetState === self::UNREADABLE) {
                    $counts['unreadable']++;

                    continue;
                }

                if ($targetState === self::PRESENT) {
                    if ($sourceState !== self::PRESENT || hash_equals(hash('sha256', $sourceBytes), hash('sha256', $targetBytes))) {
                        $counts['already_copied']++;
                    } else {
                        $counts['conflict']++;
                    }

                    continue;
                }

                if ($sourceState === self::ABSENT) {
                    $counts['missing']++;

                    continue;
                }

                // Never turn an unreadable source into an empty object.
                if ($sourceState === self::UNREADABLE) {
                    $counts['unreadable']++;

                    continue;
                }

                $counts['to_copy']++;

                if (! $apply) {
                    continue;
                }

                try {
                    $written = $target->put($key, $sourceBytes);
                } catch (Throwable $e) {
                    $written = false;
                    // Class only: the message names the object key (CG-11).
                    $this->warn('An object could not be written ('.$e::class.').');
                }

                if ($written === false) {
                    $counts['write_failed']++;

                    continue;
                }

                [$verifyState, $verifyBytes] = $this->read($target, $key);

                if ($verifyState === self::PRESENT && hash_equals(hash('sha256', $sourceBytes), hash('sha256', $verifyBytes))) {
                    $counts['copied']++;
                } else {
                    // Only an object THIS run just wrote is removed; the source stays.
                    $counts['verify_failed']++;
                    $this->forget($target, $key);
                }
            }
        }

        foreach ($counts as $name => $value) {
            $this->line(strtoupper($name).'='.$value);
        }

        $clean = $counts['conflict'] === 0
            && $counts['unreadable'] === 0
            && $counts['write_failed'] === 0
            && $counts['verify_failed'] === 0;
        $outcome = ! $apply ? 'DRY_RUN' : ($clean ? 'APPLIED' : 'INCOMPLETE');

        if ($apply) {
            $audit->log(self::AUDIT_TABLE, null, self::AUDIT_ACTION, null, $counts + ['outcome' => $outcome]);
        }

        $this->line('CONSENT_SIGNATURE_MIGRATION='.$outcome);

        if ($counts['missing'] > 0) {
            $this->warn('WATCH: '.$counts['missing'].' referenced object(s) exist on neither disk (already unreadable before this run).');
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ABSENT only when the object does not exist. An object that exists but
     * cannot be read (get() answering null on a non-throwing disk, or any
     * exception) is UNREADABLE, never confused with absent or empty.
     *
     * @return array{0: string, 1: ?string}
     */
    private function read($disk, string $key): array
    {
        try {
            if (! $disk->exists($key)) {
                return [self::ABSENT, null];
            }

            $bytes = $disk->get($key);
        } catch (Throwable) {
            return [self::UNREADABLE, null];
        }

        return is_string($bytes) ? [self::PRESENT, $bytes] : [self::UNREADABLE, null];
    }

    /** Overridable so the refusal is testable without running the suite as root. */
    protected function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function forget($disk, string $key): void
    {
        try {
            $disk->delete($key);
        } catch (Throwable) {
            // Reported through VERIFY_FAILED; never masks the run's outcome.
        }
    }
}
