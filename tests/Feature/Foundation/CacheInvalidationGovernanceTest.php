<?php

uses()->group('Foundation', 'CacheInvalidation');

it('every allowed runtime cache category has invalidation rules', function () {
    $allowed = config('cache_governance.allowed_cache_categories');
    $required = ['trigger', 'scope', 'affected_key_pattern', 'fallback', 'owner', 'tests_required'];

    foreach ($allowed as $key => $category) {
        expect($category['requires_invalidation'] ?? false)->toBeTrue("{$key} must require invalidation");

        foreach ($required as $field) {
            expect(array_key_exists($field, $category['invalidation'] ?? []))->toBeTrue("{$key} invalidation.{$field} required");
        }
    }
});

it('invalidation policy documents emergency full cache clear', function () {
    $policy = config('cache_governance.invalidation_policy');

    expect($policy['emergency_full_cache_clear'] ?? null)->toBeArray()
        ->and($policy['emergency_full_cache_clear']['command'] ?? '')->toContain('cache:clear')
        ->and($policy['branch_scoped_invalidation'] ?? false)->toBeTrue()
        ->and($policy['module_scoped_invalidation'] ?? false)->toBeTrue();
});

it('invalidation events are defined for allowed categories', function () {
    $allowed = config('cache_governance.allowed_cache_categories');

    foreach ($allowed as $key => $category) {
        expect($category['invalidation_events'] ?? [])->not->toBeEmpty("{$key} must define invalidation_events");
    }
});

it('risky allowed categories reference feature flags', function () {
    $allowed = config('cache_governance.allowed_cache_categories');

    expect($allowed['master_data.branch_reference']['feature_flag'])->toBe('foundation.cache.invalidation_governance')
        ->and($allowed['reporting.precomputed_summary_readiness']['feature_flag'])->toBe('foundation.reporting.materialized_summary_readiness');
});
