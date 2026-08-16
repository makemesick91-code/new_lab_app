<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Services\LegacyRmePatientResolutionAuditService;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-MASTERDATA-1 — diagnose a Nomor RM that will not resolve, and the
 * master-data conditions that stop one from resolving.
 *
 * WHY A COMMAND. When a legacy document's Nomor RM found no patient, the only
 * way to tell "this patient was never registered" apart from "the patient
 * master is broken" was to open a psql prompt against production. That is the
 * exact path LEGACY-RME-OPS-CLI-1 closed for import lifecycle actions, for the
 * same reason: an operational question deserves a reproducible, reviewed,
 * PII-bounded command instead of ad-hoc SQL.
 *
 * IT CHANGES NOTHING. There is no flag here that registers a patient, edits a
 * Nomor RM, merges two records or binds a document. A missing patient is
 * created through canonical registration by an authorised human; a wrong Nomor
 * RM is corrected the same way. This command only tells the truth about what is
 * there now.
 *
 * IT NEVER BINDS BY SIMILARITY. `--rm=27541` will report that `22541` exists one
 * digit away, because hiding that would send an operator hunting. It reports it
 * under `investigative_signal`, stamped `bindable => false`. Nothing may promote
 * that row to an identity — not this command, not the caller, not a later
 * sprint.
 *
 * PII POLICY. Patient id, canonical Nomor RM, branch code, counts and stable
 * codes. Never a name, KTP/NIK, phone, address, birth date or clinical detail.
 *
 * `--strict` exits non-zero only for a master-data DEFECT — a duplicated Nomor
 * RM, or a live patient whose Nomor RM the canonical parser cannot read (that
 * patient can never receive a legacy archive, because the branch resolver fails
 * closed on them). A Nomor RM that simply does not exist is a truthful answer,
 * not a failure, so it never fails the gate.
 */
class LegacyRmePatientResolutionAuditCommand extends Command
{
    protected $signature = 'legacy-rme:patient-resolution-audit
        {--rm= : Diagnose one Nomor RM (raw manual number or full canonical value)}
        {--json : Emit the report as JSON}
        {--strict : Exit non-zero when the patient master carries a defect}';

    protected $description = 'Diagnose legacy RME patient resolution for a Nomor RM and audit patient-master integrity (read-only)';

    public function handle(LegacyRmePatientResolutionAuditService $audit): int
    {
        $report = ['integrity' => $audit->integrity()];

        $rm = $this->option('rm');

        if (is_string($rm) && trim($rm) !== '') {
            $report = ['resolution' => $audit->resolve($rm)] + $report;
        }

        $defects = $this->defects($report['integrity']);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $report + ['defects' => $defects],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return $this->exitCode($defects);
        }

        $this->render($report, $defects);

        return $this->exitCode($defects);
    }

    /**
     * Conditions that are genuinely wrong, as opposed to merely notable.
     *
     * A shared multi-document binding is NOT listed: a patient legitimately owns
     * several archive pages, so failing a gate on it would train operators to
     * ignore the gate.
     *
     * @param  array<string, mixed>  $integrity
     * @return list<string>
     */
    private function defects(array $integrity): array
    {
        $defects = [];

        if ((int) ($integrity['duplicate_count'] ?? 0) > 0) {
            $defects[] = 'DUPLICATE_MEDICAL_RECORD_NUMBER';
        }

        if ((int) ($integrity['unparseable_count'] ?? 0) > 0) {
            $defects[] = 'UNPARSEABLE_MEDICAL_RECORD_NUMBER';
        }

        return $defects;
    }

    /** @param  list<string>  $defects */
    private function exitCode(array $defects): int
    {
        return ($defects !== [] && $this->option('strict')) ? 1 : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $defects
     */
    private function render(array $report, array $defects): void
    {
        if (isset($report['resolution'])) {
            $resolution = $report['resolution'];

            $this->info('Resolusi Nomor RM');
            $this->table(['Field', 'Value'], [
                ['Query', (string) $resolution['query']],
                ['Resolution', (string) $resolution['resolution']],
                ['Resolved', $resolution['resolved'] ? 'yes' : 'no'],
                ['Bindable', $resolution['bindable'] ? 'yes' : 'no'],
                ['Matches', (string) $resolution['match_count']],
            ]);
            $this->line((string) $resolution['explanation']);

            foreach ((array) $resolution['matches'] as $match) {
                $this->line(sprintf(
                    '  MATCH patient=%d rm=%s branch=%s active=%s soft_deleted=%s',
                    $match['patient_id'],
                    (string) $match['medical_record_number'],
                    (string) ($match['branch_code'] ?? '-'),
                    $match['is_active'] ? 'yes' : 'no',
                    $match['is_soft_deleted'] ? 'yes' : 'no',
                ));
            }

            $signal = (array) $resolution['investigative_signal'];

            if ($signal !== []) {
                $this->newLine();
                $this->warn('SINYAL INVESTIGASI — BUKAN IDENTITAS. Nomor berikut berbeda satu digit dan TIDAK BOLEH dipakai sebagai pasangan dokumen:');

                foreach ($signal as $near) {
                    $this->line(sprintf(
                        '  NEAR-MISS patient=%d rm=%s bindable=no',
                        $near['patient_id'],
                        (string) $near['medical_record_number'],
                    ));
                }
            }

            foreach ((array) $resolution['suffix_crossed_manual_segment'] as $crossed) {
                $this->line(sprintf(
                    '  IGNORED (segmen manual berbeda) patient=%d rm=%s',
                    $crossed['patient_id'],
                    (string) $crossed['medical_record_number'],
                ));
            }

            $this->newLine();
        }

        $integrity = $report['integrity'];

        $this->info('Integritas master pasien');
        $this->table(['Check', 'Count'], [
            ['Live patients', (string) $integrity['live_patients']],
            ['Duplicate Nomor RM', (string) $integrity['duplicate_count']],
            ['Unparseable Nomor RM (live)', (string) $integrity['unparseable_count']],
            ['Multi-document patients (published)', (string) $integrity['archive_bindings']['published']],
            ['Multi-document patients (withdrawn)', (string) $integrity['archive_bindings']['withdrawn_only']],
        ]);

        foreach ((array) $integrity['unparseable_medical_record_numbers'] as $bad) {
            $this->warn(sprintf(
                '  DEFECT patient=%d rm=%s — %s (arsip legacy tidak akan pernah bisa dibuat untuk pasien ini)',
                $bad['patient_id'],
                (string) $bad['medical_record_number'],
                (string) $bad['consequence'],
            ));
        }

        if ($defects === []) {
            $this->info('Tidak ada defect master pasien.');

            return;
        }

        $this->error('Defect: '.implode(', ', $defects));
    }
}
