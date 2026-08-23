<?php

namespace App\Console\Commands;

use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — migrate clinical evidence off the
 * publicly-served disk onto the private clinical disk.
 *
 * Two deliberate design choices:
 *
 * 1. Object keys are preserved verbatim. The same relative key is used on the
 *    destination disk, so NO database column is rewritten. A migration that
 *    does not touch the database cannot corrupt a clinical reference, and
 *    verification reduces to "does every stored path now resolve privately".
 *
 * 2. Copy and purge are separate phases. --apply copies and verifies by SHA-256
 *    but leaves the source untouched; --purge-source re-verifies each copy and
 *    only then removes the original. Nothing is ever deleted before a
 *    byte-identical copy has been proven to exist.
 *
 * Every phase writes a manifest (path, size, both checksums, outcome) which is
 * the rollback evidence: it names exactly what moved and what it hashed to.
 */
class ClinicalEvidenceMigratePublicCommand extends Command
{
    protected $signature = 'clinical-evidence:migrate-public
        {--apply : Copy and verify (default is a dry run that changes nothing)}
        {--purge-source : Re-verify copies, then delete the source objects}
        {--source=public : Disk currently holding the exposed objects}
        {--json : Emit the machine-readable summary}';

    protected $description = 'Move clinical evidence off the public disk to the private clinical disk, with checksum and DB-reference verification.';

    /** Directories on the public disk that hold patient-linked evidence. */
    private const CLINICAL_PREFIXES = ['handwritings', 'prescriptions', 'lab-orders', 'pod'];

    /** @var array<int, array{table: string, column: string}> */
    private const DB_REFERENCES = [
        ['table' => 'trx_medical_record_handwritings', 'column' => 'handwriting_path'],
        ['table' => 'trx_medical_record_handwriting_pages', 'column' => 'handwriting_path'],
        ['table' => 'trx_rme_prescriptions', 'column' => 'prescription_canvas_path'],
        ['table' => 'trx_rme_prescriptions', 'column' => 'doctor_signature_canvas_path'],
        ['table' => 'sys_attachments', 'column' => 'file_path'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $purge = (bool) $this->option('purge-source');
        $source = Storage::disk((string) $this->option('source'));
        $target = ClinicalEvidenceStorage::disk();

        $objects = $this->discover($source);
        $rows = [];
        $failures = 0;

        foreach ($objects as $key) {
            $row = ['key' => $key, 'outcome' => 'dry_run'];

            try {
                $sourceBytes = (string) $source->get($key);
                $row['size'] = strlen($sourceBytes);
                $row['source_sha256'] = hash('sha256', $sourceBytes);

                if ($target->exists($key)) {
                    $row['target_sha256'] = hash('sha256', (string) $target->get($key));
                    $row['outcome'] = $row['target_sha256'] === $row['source_sha256']
                        ? 'already_migrated'
                        : 'conflict_target_differs';
                } elseif ($apply || $purge) {
                    $target->put($key, $sourceBytes);
                    $row['target_sha256'] = hash('sha256', (string) $target->get($key));
                    $row['outcome'] = $row['target_sha256'] === $row['source_sha256']
                        ? 'copied_verified'
                        : 'copy_checksum_mismatch';
                } else {
                    $row['outcome'] = 'would_copy';
                }

                // Purge only ever runs behind a proven byte-identical copy.
                if ($purge && in_array($row['outcome'], ['copied_verified', 'already_migrated'], true)) {
                    $source->delete($key);
                    $row['source_purged'] = ! $source->exists($key);
                    $row['outcome'] = $row['source_purged'] ? 'migrated_source_purged' : 'purge_failed';
                }
            } catch (\Throwable $e) {
                // Never let one unreadable object abort the run: the remaining
                // evidence still needs migrating, and the manifest must record
                // precisely which object failed and why.
                $row['outcome'] = 'error';
                $row['error'] = $e::class;
            }

            if (str_contains((string) $row['outcome'], 'mismatch')
                || str_contains((string) $row['outcome'], 'fail')
                || str_contains((string) $row['outcome'], 'conflict')
                || $row['outcome'] === 'error') {
                $failures++;
            }

            $rows[] = $row;
        }

        $dbCheck = $this->verifyDatabaseReferences($target, $source);

        $summary = [
            'phase' => $purge ? 'purge_source' : ($apply ? 'apply' : 'dry_run'),
            'source_disk' => (string) $this->option('source'),
            'target_disk' => ClinicalEvidenceStorage::diskName(),
            'objects_seen' => count($rows),
            'object_failures' => $failures,
            'db_references_checked' => $dbCheck['checked'],
            'db_references_resolved' => $dbCheck['resolved'],
            'db_references_unresolved' => $dbCheck['unresolved'],
            'db_references_dangling_before_migration' => $dbCheck['dangling_before_migration'],
            'db_references_broken_by_migration' => $dbCheck['broken_by_migration'],
            'db_references_inline' => $dbCheck['inline'],
            'source_objects_remaining' => count($this->discover($source)),
        ];
        // A reference whose object is absent from the SOURCE too was already
        // dangling before this command ran — the migration did not break it and
        // cannot repair it. Counting it as a failure would make the decision
        // permanently red on any database carrying historic orphan rows, which
        // is precisely how a gate stops being read. Only a reference the
        // migration itself failed to carry across is a migration failure.
        $summary['decision'] = ($failures === 0 && $dbCheck['broken_by_migration'] === 0) ? 'OK' : 'FAIL';

        $manifestPath = $this->writeManifest($summary, $rows, $dbCheck);
        $summary['manifest'] = $manifestPath;

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($summary as $k => $v) {
                $this->line(sprintf('%-28s %s', $k, is_bool($v) ? var_export($v, true) : (string) $v));
            }
        }

        return $summary['decision'] === 'OK' ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function discover(Filesystem $disk): array
    {
        $keys = [];

        foreach (self::CLINICAL_PREFIXES as $prefix) {
            foreach ($disk->allFiles($prefix) as $file) {
                $keys[] = $file;
            }
        }

        return $keys;
    }

    /**
     * Confirm every stored clinical path resolves on the private disk.
     *
     * Inline data-URI rows are counted separately: they never touched the
     * filesystem, so "not on disk" is correct for them rather than a defect.
     *
     * @return array{checked: int, resolved: int, unresolved: int, dangling_before_migration: int, broken_by_migration: int, inline: int, unresolved_samples: list<string>}
     */
    private function verifyDatabaseReferences(Filesystem $target, Filesystem $source): array
    {
        $checked = $resolved = $unresolved = $inline = 0;
        $danglingBefore = $brokenByMigration = 0;
        $samples = [];

        foreach (self::DB_REFERENCES as $ref) {
            if (! DB::getSchemaBuilder()->hasTable($ref['table'])) {
                continue;
            }

            DB::table($ref['table'])
                ->whereNotNull($ref['column'])
                ->where($ref['column'], '!=', '')
                ->orderBy('id')
                ->select(['id', $ref['column']])
                ->chunk(500, function ($records) use ($ref, $target, $source, &$checked, &$resolved, &$unresolved, &$inline, &$danglingBefore, &$brokenByMigration, &$samples) {
                    foreach ($records as $record) {
                        $path = (string) $record->{$ref['column']};
                        $checked++;

                        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
                            $inline++;

                            continue;
                        }

                        if ($target->exists($path)) {
                            $resolved++;

                            continue;
                        }

                        $unresolved++;

                        if ($source->exists($path)) {
                            // The object is still on the source disk but did not
                            // reach the target: this migration genuinely failed
                            // to carry it, and that must block.
                            $brokenByMigration++;
                        } else {
                            $danglingBefore++;
                        }

                        // Table/column/id only — never the patient-linked path
                        // itself, which encodes branch and visit identifiers.
                        if (count($samples) < 20) {
                            $samples[] = sprintf('%s#%s.%s', $ref['table'], $record->id, $ref['column']);
                        }
                    }
                });
        }

        return [
            'checked' => $checked,
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'dangling_before_migration' => $danglingBefore,
            'broken_by_migration' => $brokenByMigration,
            'inline' => $inline,
            'unresolved_samples' => $samples,
        ];
    }

    private function writeManifest(array $summary, array $rows, array $dbCheck): string
    {
        $dir = storage_path('app/clinical-evidence-migration');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/manifest-'.$summary['phase'].'-'.date('Ymd-His').'.json';

        file_put_contents($file, (string) json_encode([
            'sprint' => 'STORAGE-PUBLIC-CLINICAL-EVIDENCE-1',
            'generated_at' => date('Y-m-d\TH:i:s\Z'),
            'summary' => $summary,
            'database_reference_check' => $dbCheck,
            'objects' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }
}
