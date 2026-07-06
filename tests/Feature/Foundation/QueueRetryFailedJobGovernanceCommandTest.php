<?php

use App\Support\Queue\EnterpriseQueueJob;
use App\Support\Queue\QueueRetryFailedJobReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation');

class Ent5FixtureFailingJob extends EnterpriseQueueJob
{
    public $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('ENT-5 fixture failure');
    }
}

class Ent5FixtureSuccessJob extends EnterpriseQueueJob
{
    public static bool $handled = false;

    public function handle(): void
    {
        self::$handled = true;
    }
}

it('passes with GO on the repo default configuration', function () {
    $report = app(QueueRetryFailedJobReadinessService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('queue_retry_ready')
        ->and($report['failed_jobs_table_exists'])->toBeTrue()
        ->and($report['queued_classes_non_compliant'])->toBe([]);

    expect(Artisan::call('foundation:queue-retry-failed-job-check'))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});

it('emits a machine-readable JSON report', function () {
    Artisan::call('foundation:queue-retry-failed-job-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ENT-5')
        ->and($report)->toHaveKeys([
            'decision', 'readiness_status', 'queue_connection', 'failed_driver',
            'failed_jobs_table_exists', 'retry_standards', 'queued_classes_total',
            'queued_classes_non_compliant', 'checks', 'summary',
        ]);
});

it('fails when the queue connection is forbidden for the environment', function () {
    config(['app.env' => 'production', 'queue.default' => 'sync']);

    $report = app(QueueRetryFailedJobReadinessService::class)->collect();
    $check = collect($report['checks'])->firstWhere('check_id', 'ENT5-Q001-CONNECTION-POLICY');

    expect($report['decision'])->toBe('FAIL')
        ->and($check['status'])->toBe('failed');

    expect(Artisan::call('foundation:queue-retry-failed-job-check'))->toBe(1);
});

it('fails when the failed job driver is not database-uuids', function () {
    config(['queue.failed.driver' => 'null']);

    $report = app(QueueRetryFailedJobReadinessService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT5-Q002-FAILED-DRIVER')['status'])->toBe('failed');
});

it('reports WATCH when the failed job table is missing and only strict mode turns that into failure', function () {
    config(['queue_governance.ent5_retry_failed_job.failed_jobs.required_table' => 'ent5_missing_failed_jobs']);

    $report = app(QueueRetryFailedJobReadinessService::class)->collect();

    expect($report['decision'])->toBe('WATCH')
        ->and($report['readiness_status'])->toBe('warning')
        ->and($report['failed_jobs_table_exists'])->toBeFalse();

    expect(Artisan::call('foundation:queue-retry-failed-job-check'))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(1)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--fail-on-warning' => true]))->toBe(1);
});

it('fails when the central retry standard breaks its own caps', function () {
    config(['queue_governance.ent5_retry_failed_job.retry_standards.default_tries' => 10]);

    $report = app(QueueRetryFailedJobReadinessService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT5-Q003-RETRY-STANDARD')['status'])->toBe('failed');
});

it('flags a ShouldQueue class without an approved retry policy', function () {
    $dir = base_path('storage/framework/testing/ent5-scan');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/NakedJob.php', "<?php\nclass Ent5ScanFixtureNakedJob implements ShouldQueue {}\n");

    try {
        config(['queue_governance.ent5_retry_failed_job.job_scan.paths' => ['storage/framework/testing/ent5-scan']]);

        $report = app(QueueRetryFailedJobReadinessService::class)->collect();

        expect($report['decision'])->toBe('FAIL')
            ->and($report['queued_classes_total'])->toBe(1)
            ->and($report['queued_classes_non_compliant'])->toHaveCount(1);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('tolerates a scan across the real app tree (zero or many jobs) without flagging compliant classes', function () {
    $report = app(QueueRetryFailedJobReadinessService::class)->collect();

    expect($report['queued_classes_total'])->toBeGreaterThanOrEqual(0)
        ->and($report['queued_classes_non_compliant'])->toBe([]);
});

it('detects destructive queue command automation in scanned code', function () {
    $dir = base_path('storage/framework/testing/ent5-unsafe');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/Unsafe.php', "<?php\nArtisan::call('queue:flush');\n");

    try {
        config(['queue_governance.ent5_retry_failed_job.unsafe_command_scan.paths' => ['storage/framework/testing/ent5-unsafe']]);

        $report = app(QueueRetryFailedJobReadinessService::class)->collect();

        expect($report['decision'])->toBe('FAIL')
            ->and(collect($report['checks'])->firstWhere('check_id', 'ENT5-Q005-NO-UNSAFE-COMMANDS')['status'])->toBe('failed');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('applies the central retry standard to jobs extending EnterpriseQueueJob and respects explicit overrides', function () {
    $job = new class extends EnterpriseQueueJob
    {
        public function handle(): void {}
    };

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 180])
        ->and($job->timeout)->toBe(120);

    $overridden = new Ent5FixtureFailingJob;

    expect($overridden->tries)->toBe(1)
        ->and($overridden->backoff)->toBe([10, 60, 180]);
});

it('processes a queued job end-to-end on the database connection', function () {
    config(['queue.default' => 'database']);
    Ent5FixtureSuccessJob::$handled = false;

    Ent5FixtureSuccessJob::dispatch();

    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(Ent5FixtureSuccessJob::$handled)->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('stores a failing job in failed_jobs and supports per-uuid retry and forget', function () {
    config(['queue.default' => 'database']);

    Ent5FixtureFailingJob::dispatch();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $failed = DB::table('failed_jobs')->first();
    expect($failed->uuid)->not->toBeNull()
        ->and($failed->exception)->toContain('ENT-5 fixture failure');

    Artisan::call('queue:retry', ['id' => [$failed->uuid]]);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1);

    $second = DB::table('failed_jobs')->first();
    Artisan::call('queue:forget', ['id' => $second->uuid]);

    expect(DB::table('failed_jobs')->count())->toBe(0);
});
