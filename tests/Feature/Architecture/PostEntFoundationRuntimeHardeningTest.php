<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\EntFoundationRuntimeHardeningGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationGovernance', 'EnterpriseFoundation', 'PostEntHardening', 'QueueWorker', 'DeployEvidence');

function hardeningService(): EntFoundationRuntimeHardeningGovernanceService
{
    return app(EntFoundationRuntimeHardeningGovernanceService::class);
}

it('ships the post-ENT runtime hardening governance doc with all PEH rules', function () {
    $doc = file_get_contents(base_path('docs/architecture/post-enterprise-foundation-runtime-hardening-governance.md'));

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('PEH-R%03d', $n));
    }

    expect($doc)->toContain('foundation:runtime-hardening-check')
        ->and($doc)->toContain('daengtisiams-queue-worker.service')
        ->and($doc)->toContain('scripts/deploy-vps-runner.sh')
        ->and($doc)->toContain('enterprise-foundation-go')
        ->and($doc)->toContain('MON-1')
        ->and($doc)->toContain('NOT ENT-17');
});

it('keeps the post-ENT governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/post-enterprise-foundation-runtime-hardening-governance.md'),
        base_path('docs/sprints/post-ent-foundation-runtime-hardening.md'),
        base_path('docs/runbooks/queue-worker-activation-runbook.md'),
        base_path('docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md'),
    ];

    foreach ($paths as $path) {
        $doc = file_get_contents($path);
        foreach ((array) config('release_evidence.forbidden_patterns') as $pattern) {
            expect(str_contains($doc, $pattern))->toBeFalse("{$path} contains forbidden literal {$pattern}");
        }
        foreach ((array) config('release_evidence.forbidden_regex') as $regex) {
            expect(preg_match($regex, $doc))->toBe(0, "{$path} matches forbidden regex {$regex}");
        }
    }
});

it('exposes the runtime hardening config with the ENT-1..4 audit expectations and closed baseline', function () {
    expect(config('enterprise_foundation_runtime_hardening.baseline.final_closure_tag'))->toBe('enterprise-foundation-go')
        ->and(config('enterprise_foundation_runtime_hardening.baseline.next_recommended_sprint'))->toBe('MON-1')
        ->and(config('enterprise_foundation_runtime_hardening.baseline.is_ent_17'))->toBeFalse()
        ->and(array_keys((array) config('enterprise_foundation_runtime_hardening.ent_1_4_audit.expectations')))
        ->toBe(['ENT-1', 'ENT-2', 'ENT-3', 'ENT-4']);
});

it('audits ENT-1..ENT-4 as GO governance/config/docs locks without requiring runtime backfill', function () {
    $report = hardeningService()->collectEntAudit();

    expect($report['decision'])->toBe('GO')
        ->and($report['runtime_backfill_required'])->toBeFalse()
        ->and($report['audited_sprints'])->toBe(['ENT-1', 'ENT-2', 'ENT-3', 'ENT-4']);

    foreach ($report['per_sprint'] as $id => $sprint) {
        expect($sprint['ok'])->toBeTrue("{$id} audit not OK")
            ->and($sprint['status'])->toBe('completed')
            ->and($sprint['go_tag'])->not->toBe('')
            ->and($sprint['runtime_backfill_required'])->toBeFalse();
    }
});

it('fails the ENT-1..4 audit when a mandatory canonical doc is missing', function () {
    config()->set('enterprise_foundation_runtime_hardening.ent_1_4_audit.expectations.ENT-2.required_docs', [
        'docs/architecture/this-doc-does-not-exist.md',
    ]);

    $report = hardeningService()->collectEntAudit();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['per_sprint']['ENT-2']['ok'])->toBeFalse();
});

it('passes the queue worker runtime check with the conservative systemd unit', function () {
    $report = hardeningService()->collectQueueWorker();

    expect($report['decision'])->toBe('GO')
        ->and($report['service_file_present'])->toBeTrue()
        ->and($report['service_name'])->toBe('daengtisiams-queue-worker.service')
        ->and($report['worker_command_present'])->toBeTrue()
        ->and($report['no_destructive_command'])->toBeTrue()
        ->and($report['queues_subset_of_ent5'])->toBeTrue()
        ->and($report['connection_ok'])->toBeTrue()
        ->and($report['activated_by_deploy'])->toBeFalse();
});

it('fails the queue worker runtime check when the systemd unit is missing', function () {
    config()->set('enterprise_foundation_runtime_hardening.queue_worker.service_file', 'deploy/systemd/missing-unit.service');

    expect(hardeningService()->collectQueueWorker()['decision'])->toBe('FAIL');
});

it('fails the queue worker runtime check when the connection is invalid for the environment', function () {
    config()->set('queue_governance.ent5_retry_failed_job.environment_connection_policy.testing', ['database', 'redis']);
    config()->set('queue.default', 'sync');

    $report = hardeningService()->collectQueueWorker();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['connection_ok'])->toBeFalse();
});

it('defines a safe conservative queue worker systemd unit', function () {
    $unit = file_get_contents(base_path('deploy/systemd/daengtisiams-queue-worker.service'));

    expect($unit)->toContain('artisan queue:work')
        ->and($unit)->toContain('--sleep=3')
        ->and($unit)->toContain('--tries=3')
        ->and($unit)->toContain('--timeout=120')
        ->and($unit)->toContain('--memory=256')
        ->and($unit)->toContain('--max-time=')
        ->and($unit)->toContain('Restart=')
        ->and($unit)->toContain('WorkingDirectory=/var/www/asia-dental-lab-v2')
        // INFRA-SEC-RUNTIME-1 repin: the worker moved off the shared www-data
        // account onto the dedicated DaengtisiaMS runtime identity.
        ->and($unit)->toContain('User=daengtisiams')
        ->and($unit)->toContain('Group=daengtisiams')
        ->and($unit)->not->toContain('User=www-data');

    // Never a listener/daemon or destructive queue/db command.
    foreach (['queue:listen', '--daemon', 'queue:flush', 'queue:clear', 'migrate:fresh', 'db:wipe', 'schema:drop'] as $forbidden) {
        expect(str_contains($unit, $forbidden))->toBeFalse("unit contains forbidden {$forbidden}");
    }
});

it('provides a detached deploy runner that survives an SSH broken pipe', function () {
    $runner = file_get_contents(base_path('scripts/deploy-vps-runner.sh'));

    expect($runner)->toContain('set -euo pipefail')
        ->and($runner)->toContain('setsid')
        ->and($runner)->toContain('nohup')
        ->and($runner)->toContain('DEPLOY_STATUS_FILE')
        ->and($runner)->toContain('DEPLOY_LOG_FILE')
        ->and($runner)->toContain('scripts/deploy-vps.sh')
        ->and($runner)->toContain('echo "exit=');

    foreach (['migrate:fresh', 'db:wipe', 'schema:drop', 'migrate:reset'] as $forbidden) {
        expect(str_contains($runner, $forbidden))->toBeFalse("runner contains forbidden {$forbidden}");
    }
});

it('keeps the deploy script backup-first, migrate --force, and ENT-8 cache order', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($deploy)->toContain('pg_dump')
        ->and($deploy)->toContain('test -s')
        ->and($deploy)->toContain('php artisan migrate --force')
        ->and($deploy)->toContain('php artisan route:clear')
        ->and($deploy)->toContain('php artisan config:clear')
        ->and($deploy)->toContain('DEPLOY OK')
        ->and($deploy)->toContain('foundation:runtime-hardening-check');

    foreach (['migrate:fresh', 'db:wipe', 'schema:drop', 'migrate:reset'] as $forbidden) {
        expect(str_contains($deploy, $forbidden))->toBeFalse("deploy contains forbidden {$forbidden}");
    }
});

it('declares the hardening evidence artifacts as optional in the ci and vps profiles', function () {
    foreach (['ci', 'vps'] as $profile) {
        $optional = (array) config("release_evidence.profiles.{$profile}.optional_artifacts");
        expect($optional)->toContain('ent-1-4-audit-check.json')
            ->and($optional)->toContain('queue-worker-runtime-check.json')
            ->and($optional)->toContain('runtime-hardening-check.json');
    }
});

it('runs the umbrella runtime hardening check GO and re-verifies the closed baseline', function () {
    $report = hardeningService()->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['ent_1_4_audit_ok'])->toBeTrue()
        ->and($report['queue_worker_ok'])->toBeTrue()
        ->and($report['deploy_evidence_timeout_ok'])->toBeTrue()
        ->and($report['evidence_profiles_ok'])->toBeTrue()
        ->and($report['closed_baseline_decision'])->toBe('GO')
        ->and($report['final_closure_tag'])->toBe('enterprise-foundation-go')
        ->and($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($report['is_ent_17'])->toBeFalse();
});

it('publishes an informational hardening section into the foundation governance summary', function () {
    $report = app(FoundationGovernanceSummaryService::class)->collect('local');
    $section = $report['enterprise_foundation_runtime_hardening_governance'] ?? null;

    expect($section)->not->toBeNull()
        ->and($section['command'])->toBe('foundation:runtime-hardening-check')
        ->and($section['final_closure_tag'])->toBe('enterprise-foundation-go')
        ->and($section['is_ent_17'])->toBeFalse();
});

it('registers the post-ENT hardening artisan commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('foundation:ent-1-4-audit-check')
        ->and($commands)->toContain('foundation:queue-worker-runtime-check')
        ->and($commands)->toContain('foundation:runtime-hardening-check')
        ->and($commands)->toContain('foundation:queue-worker-smoke');
});

it('runs the strict hardening gates with exit code zero', function () {
    expect(Artisan::call('foundation:ent-1-4-audit-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-worker-runtime-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:runtime-hardening-check', ['--strict' => true]))->toBe(0);
});

it('keeps the enterprise closure GO and MON-1 as next recommended sprint', function () {
    expect(Artisan::call('foundation:enterprise-closure-check', ['--strict' => true]))->toBe(0);

    $roadmap = app(FoundationRoadmapService::class)->collect();
    expect($roadmap['next_recommended_sprint'] ?? null)->toBe('MON-1');
});

it('dispatches the harmless queue worker smoke job without error', function () {
    expect(Artisan::call('foundation:queue-worker-smoke', ['--process' => true]))->toBe(0);
});

it('keeps hardening rule text non-sensitive', function () {
    $rules = EntFoundationRuntimeHardeningGovernanceService::rules();
    $blob = json_encode($rules);

    foreach ((array) config('release_evidence.forbidden_patterns') as $pattern) {
        expect(str_contains($blob, $pattern))->toBeFalse("rule text contains forbidden literal {$pattern}");
    }
    foreach ((array) config('release_evidence.forbidden_regex') as $regex) {
        expect(preg_match($regex, $blob))->toBe(0);
    }
});
