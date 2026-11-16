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
 * key, consent number or patient detail is printed.
 */
class RmeConsentSignaturesMigrateCommand extends Command
{
    protected $signature = 'rme:consent-signatures-migrate {--apply : Copy the objects (default: dry run, nothing written)}';

    protected $description = 'Copy consent signature objects from the legacy private disk to the clinical evidence disk, key for key, SHA-256 verified (dry run unless --apply; never deletes).';

    public const AUDIT_TABLE = 'trx_rme_visit_consents';

    public const AUDIT_ACTION = 'RME_CONSENT_SIGNATURE_STORAGE_COPIED';

    public function handle(RmeVisitConsentRepositoryInterface $consents, AuditLogService $audit): int
    {
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

                $sourceBytes = $this->read($source, $key);
                $targetBytes = $this->read($target, $key);

                if ($targetBytes !== null) {
                    if ($sourceBytes === null || hash_equals(hash('sha256', $sourceBytes), hash('sha256', $targetBytes))) {
                        $counts['already_copied']++;
                    } else {
                        $counts['conflict']++;
                    }

                    continue;
                }

                if ($sourceBytes === null) {
                    $counts['missing']++;

                    continue;
                }

                $counts['to_copy']++;

                if (! $apply) {
                    continue;
                }

                $target->put($key, $sourceBytes);
                $written = $this->read($target, $key);

                if ($written !== null && hash_equals(hash('sha256', $sourceBytes), hash('sha256', $written))) {
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

        $clean = $counts['conflict'] === 0 && $counts['verify_failed'] === 0;
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

    private function read($disk, string $key): ?string
    {
        try {
            return $disk->exists($key) ? (string) $disk->get($key) : null;
        } catch (Throwable) {
            return null;
        }
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
