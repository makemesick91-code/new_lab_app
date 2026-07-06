<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\BackupDrGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'BackupDr');

it('ships the backup & disaster recovery automation governance doc with all ENT12 rules', function () {
    $path = base_path('docs/architecture/backup-disaster-recovery-automation-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT12-BDR%03d', $n));
    }

    expect($doc)->toContain('foundation:backup-dr-check')
        ->and($doc)->toContain('backup_dr_governance')
        ->and($doc)->toContain('scripts/backup-vps.sh')
        ->and($doc)->toContain('scripts/restore-rehearsal.sh')
        ->and($doc)->toContain('backup-dr-check.json')
        ->and($doc)->toContain('RTO')
        ->and($doc)->toContain('RPO')
        ->and($doc)->toContain('pg_dump');
});

it('keeps the ENT-12 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/backup-disaster-recovery-automation-governance.md'),
        base_path('docs/sprints/ent-12-backup-disaster-recovery-automation.md'),
    ];

    foreach ($paths as $path) {
        $doc = file_get_contents($path);

        foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
            expect(str_contains($doc, $pattern))->toBeFalse(
                basename($path)." must not contain forbidden release-evidence literal: {$pattern}"
            );
        }

        foreach (config('release_evidence.forbidden_regex', []) as $regex) {
            expect(preg_match($regex, $doc))->toBe(0);
        }
    }
});

it('references ENT-12 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/61-backup-disaster-recovery-automation.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('backup-disaster-recovery-automation-governance.md')
        ->and($freeze)->toContain('foundation:backup-dr-check')
        ->and($cursor)->toContain('scripts/restore-rehearsal.sh')
        ->and($cursor)->toContain('foundation:backup-dr-check --strict')
        ->and($claude)->toContain('ENT-12 — Backup & Disaster Recovery Automation')
        ->and($claude)->toContain('backup_dr_governance');
});

it('registers the ENT-12 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.backup_dr_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.backup_dr_governance_doc'))->toBe('docs/architecture/backup-disaster-recovery-automation-governance.md')
        ->and(config('backup_dr.enabled'))->toBeTrue()
        ->and(config('backup_dr.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('backup_dr.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('backup_dr.evidence.artifact'))->toBe('backup-dr-check.json')
        ->and(config('backup_dr.objectives.rto_minutes'))->toBeGreaterThan(0)
        ->and(config('backup_dr.objectives.rpo_minutes'))->toBeGreaterThan(0);
});

it('registers ENT-12 completed and moves next recommended sprint to ENT-14', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent12 = $sequence->firstWhere('id', 'ENT-12');

    expect($ent12)->not->toBeNull()
        ->and($ent12['status'])->toBe('completed')
        ->and($ent12['category'])->toBe('backup_dr')
        ->and($ent12['governance_section'])->toBe('backup_dr_governance')
        ->and($ent12['readiness_command'])->toBe('foundation:backup-dr-check')
        ->and($ent12['policy_doc'])->toBe('docs/architecture/backup-disaster-recovery-automation-governance.md')
        ->and($ent12['go_tag'])->toBe('ent-12-backup-disaster-recovery-automation-go')
        ->and($ent12['related_shipped_foundations'])->toContain('NSF-10');

    $ent11 = $sequence->firstWhere('id', 'ENT-11');
    expect($ent11['deploy_evidence_commit'])->toBe('aa14d9d');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-15 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $entry = $sequence->firstWhere('id', 'MON-1');
    expect($entry)->not->toBeNull()
        ->and($entry['status'])->toBe('planned');
});

it('publishes ENT12 rules through the governance service and foundation summary', function () {
    $rules = BackupDrGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT12-BDR%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('backup_dr_governance')
        ->and($summary['backup_dr_governance']['command'])->toBe('foundation:backup-dr-check')
        ->and($summary['backup_dr_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['backup_dr_governance']['rules'], 'id'))->toContain('ENT12-BDR001')
        ->and($summary)->toHaveKeys(['deployment_rollback_governance', 'cicd_enterprise_gate_governance', 'security_compliance_governance']);
});

it('requires the ENT-12 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('backup-dr-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('backup-dr-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:backup-dr-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-12');
});

it('ships a safe automated backup script that verifies, prunes, and carries no destructive command', function () {
    $path = base_path('scripts/backup-vps.sh');
    expect(file_exists($path))->toBeTrue();

    $backup = file_get_contents($path);

    expect($backup)->toContain('set -euo pipefail')
        ->and($backup)->toContain('pg_dump')
        ->and($backup)->toContain('php artisan foundation:backup-verify')
        ->and($backup)->toContain('BACKUP_DR_RETENTION_DAYS');

    foreach (config('backup_dr.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($backup, (string) $pattern))->toBeFalse(
            "backup script must not contain destructive pattern: {$pattern}"
        );
    }
});

it('ships a non-production restore rehearsal guarded against the production database', function () {
    $path = base_path('scripts/restore-rehearsal.sh');
    expect(file_exists($path))->toBeTrue();

    $rehearsal = file_get_contents($path);

    expect($rehearsal)->toContain('set -euo pipefail')
        ->and($rehearsal)->toContain('REHEARSAL_DB')
        ->and($rehearsal)->toContain('must differ from the production database')
        ->and($rehearsal)->toContain('pg_dump')
        ->and($rehearsal)->toContain('php artisan foundation:backup-verify')
        ->and($rehearsal)->toContain('RTO')
        ->and($rehearsal)->toContain('RPO');

    // The rehearsal must never invoke the production restore helper.
    expect(str_contains($rehearsal, 'restore_postgres.sh'))->toBeFalse();

    foreach (config('backup_dr.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($rehearsal, (string) $pattern))->toBeFalse(
            "restore rehearsal must not contain destructive pattern: {$pattern}"
        );
    }
});

it('keeps the deploy and CI scripts running the ENT-12 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:backup-dr-check')
        ->and($deploy)->toContain('backup-dr-check.json')
        ->and($ciScript)->toContain('foundation:backup-dr-check');
});

it('foundation roadmap check stays green after the ENT-12 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-12 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
