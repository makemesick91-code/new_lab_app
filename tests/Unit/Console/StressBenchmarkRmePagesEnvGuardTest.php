<?php

use Tests\TestCase;

uses(TestCase::class);

it('blocks benchmark in pilot and production environments', function () {
    foreach (['pilot', 'production'] as $env) {
        app()['env'] = $env;

        $this->artisan('stress:benchmark-rme-pages', ['--dry-run' => true])
            ->expectsOutputToContain('never pilot/production')
            ->assertExitCode(1);
    }

    app()['env'] = 'testing';
});
