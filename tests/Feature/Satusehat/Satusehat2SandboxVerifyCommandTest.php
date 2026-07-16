<?php

use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ssConfigureSandbox(sendEnabled: false));

it('runs a dry-run without any network call and never prints the secret', function () {
    Http::fake();

    $this->artisan('satusehat:sandbox-verify', ['--patient-ihs' => 'P1', '--practitioner-ihs' => 'D1'])
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('refuses to run against production', function () {
    config()->set('satusehat.environment', 'production');
    config()->set('satusehat.sandbox_only', false);

    $this->artisan('satusehat:sandbox-verify')->assertExitCode(1);
});

it('requires --confirm-sandbox for a live run', function () {
    $this->artisan('satusehat:sandbox-verify', ['--live' => true])->assertExitCode(1);
});

it('refuses live when the gateway is not enabled', function () {
    Http::fake();

    $this->artisan('satusehat:sandbox-verify', ['--live' => true, '--confirm-sandbox' => true, '--patient-ihs' => 'P1'])
        ->assertExitCode(1);

    Http::assertNothingSent();
});
