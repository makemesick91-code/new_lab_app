<?php

use App\Console\Commands\StressBenchmarkRmePagesCommand;

it('clamps runs between 1 and 100 via command options', function () {
    $command = new StressBenchmarkRmePagesCommand;

    expect($command->getDefinition()->getOption('runs')->getDefault())->toBe('5');
    expect($command->getDefinition()->getOption('include-owner')->getDefault())->toBeFalse();
    expect($command->getDefinition()->getOption('json')->getDefault())->toBeFalse();
    expect($command->getDefinition()->getOption('warmup')->getDefault())->toBe('1');
    expect($command->getDefinition()->getOption('branch-code')->getDefault())->toBe('TST');
});

it('defines owner dashboard labels in the benchmark target builder', function () {
    $command = new class extends StressBenchmarkRmePagesCommand
    {
        /**
         * @param  array{branch_id:int,visit_id:int,patient_id:int}  $context
         * @return list<array{label:string,method:string,route:string,path:string,session:string}>
         */
        public function exposedTargets(string $baseUrl, array $context, bool $includeOwner): array
        {
            return $this->buildTargets($baseUrl, $context, $includeOwner);
        }
    };

    $context = ['branch_id' => 9, 'visit_id' => 100001, 'patient_id' => 50001];
    $baseTargets = $command->exposedTargets('http://127.0.0.1:8008', $context, false);
    $ownerTargets = $command->exposedTargets('http://127.0.0.1:8008', $context, true);

    expect(collect($baseTargets)->pluck('label')->all())->toContain(
        'rme_visits',
        'rme_receivables',
        'rme_reports_payments',
        'rme_patient_queue',
    );

    expect(collect($ownerTargets)->pluck('label')->all())->toContain(
        'owner_dashboard',
        'owner_dashboard_kpi_month',
        'owner_dashboard_branch',
    );

    expect(count($ownerTargets))->toBe(count($baseTargets) + 3);
});

it('builds json dry-run payload without patient-identifying fields', function () {
    $payload = [
        'dry_run' => true,
        'environment' => 'testing',
        'branch_code' => 'TST',
        'runs' => 2,
        'warmup' => 1,
        'targets' => [[
            'label' => 'owner_dashboard',
            'method' => 'GET',
            'route' => 'dashboard',
            'path' => 'http://127.0.0.1:8008/dashboard',
            'session' => 'owner',
        ]],
    ];

    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($json)->toBeString()
        ->and($json)->not->toContain('KTP')
        ->and($json)->not->toContain('NIK')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['targets'][0]['label'])
        ->toBe('owner_dashboard');
});
