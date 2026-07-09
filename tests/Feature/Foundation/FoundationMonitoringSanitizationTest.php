<?php

use App\Services\Foundation\FoundationMonitoringStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('Foundation', 'FoundationMonitoring', 'EnterpriseFoundation');

beforeEach(function () {
    seedAccessControl();
});

it('masks sensitive data in the application-log error excerpt', function () {
    $relative = 'logs/mon1-sanitize-'.uniqid().'.log';
    $absolute = storage_path($relative);
    file_put_contents(
        $absolute,
        "[2026-07-09 10:00:00] production.ERROR: KTP 3201234567890123 leaked password=super-secret-value budi@example.com\n"
    );
    config(['foundation_monitoring.paths.laravel_log' => $relative]);

    try {
        $report = app(FoundationMonitoringStatusService::class)->collect();
        $log = collect($report['signals'])->firstWhere('key', 'laravel_log');
        $excerpt = (string) ($log['details']['last_error_excerpt'] ?? '');

        expect($excerpt)->not->toContain('3201234567890123')
            ->and($excerpt)->not->toContain('super-secret-value')
            ->and($excerpt)->not->toContain('budi@')
            ->and($excerpt)->toContain('[MASKED]');
    } finally {
        @unlink($absolute);
    }
});

it('never renders env secrets, DB password, KTP, or stack traces on the monitoring page', function () {
    // Seed a failed job whose payload/exception carry sensitive content.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"secret":"3201234567890123"}',
        'exception' => "Exception: password=top-secret KTP 3271234567890001\n#0 /app/Foo.php(1)",
        'failed_at' => now(),
    ]);

    $response = $this->actingAs(superAdmin())->get(route('foundation.monitoring.index'));

    $response->assertOk()
        ->assertDontSee('3201234567890123')
        ->assertDontSee('3271234567890001')
        ->assertDontSee('top-secret')
        ->assertDontSee((string) config('app.key'));

    $dbPassword = (string) config('database.connections.'.config('database.default').'.password');
    if ($dbPassword !== '') {
        $response->assertDontSee($dbPassword);
    }
});

it('never exposes a raw failed-job payload in the JSON report', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"secret":"raw-payload-marker-xyz"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $report = app(FoundationMonitoringStatusService::class)->collect();

    expect(json_encode($report))->not->toContain('raw-payload-marker-xyz');
});
