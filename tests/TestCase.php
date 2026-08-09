<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * PHP feature tests must not depend on a compiled frontend.
         *
         * `layouts/app.blade.php` calls @vite, which throws
         * ViteManifestNotFoundException when public/build/manifest.json is
         * absent. Every test that renders a page extending that layout then
         * gets HTTP 500 instead of 200 — one root cause presenting as dozens of
         * unrelated-looking failures.
         *
         * That is exactly what happens in the critical regression gate: it
         * installs Composer dependencies only and runs no npm build, so the
         * manifest never exists there. It also happens on any developer machine
         * that has not run `npm run build` recently.
         *
         * These are PHP tests asserting HTTP status, authorization and content
         * — not asset-pipeline tests. withoutVite() is Laravel's own mechanism
         * for exactly this: it swaps in a no-op Vite instance so @vite renders
         * nothing. Nothing is skipped and no assertion is weakened; the tests
         * still render the real views and still assert the real responses.
         *
         * Tests that genuinely inspect build output (PerformanceAssetWeightUix)
         * read public/build from the filesystem directly rather than through the
         * Vite facade, so they are unaffected and keep their own guards.
         */
        $this->withoutVite();
    }
}
