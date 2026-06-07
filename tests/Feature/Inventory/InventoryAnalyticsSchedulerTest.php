<?php

use Illuminate\Support\Facades\Artisan;

it('registers inventory analytics summary refresh command', function () {
    expect(Artisan::all())->toHaveKey('inventory:analytics-summary:refresh');
});

it('registers inventory analytics summary prune command', function () {
    expect(Artisan::all())->toHaveKey('inventory:analytics-summary:prune');
});

it('lists analytics summary refresh in schedule without error', function () {
    $exitCode = Artisan::call('schedule:list');

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('inventory:analytics-summary:refresh')
        ->toContain('30 1 * * *')
        ->not->toContain('migrate:fresh')
        ->not->toContain('db:wipe');
});

it('lists optional monthly prune in schedule', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toContain('inventory:analytics-summary:prune')
        ->toContain('30 2 1 * *');
});

it('does not change analytics summary feature flag default', function () {
    expect(config('inventory.analytics_summary_enabled'))->toBeFalse();
});

it('defaults analytics summary retention to 730 days', function () {
    expect(config('inventory.analytics_summary_retention_days'))->toBe(730);
});

it('refresh command runs successfully when invoked directly', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', ['--all' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('completed');
});
