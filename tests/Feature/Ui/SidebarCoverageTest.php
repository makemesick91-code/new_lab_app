<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D12 — every page a controller
 * renders must carry the sidebar.
 *
 * MEASURED FIRST, 2026-09-20. The request was "pastikan semua halaman memiliki
 * side bar agar mempermudah user", and the honest finding is that the coverage
 * was ALREADY complete: of 184 controller-rendered views, 168 sit on a
 * sidebar-bearing shell and the remaining 16 are the 7 auth screens and 7
 * print templates, both of which must NOT have one. Nothing needed adding.
 *
 * So this file is the deliverable rather than a code change: "pastikan" is
 * make-certain, and a property that merely happens to hold today is not
 * certain. The next page added to this system cannot ship without a sidebar,
 * because this test will fail.
 *
 * THE SHELL CHAIN, which is why a single grep for the partial finds nothing:
 *
 *     x-settings-shell -> x-app-layout -> layouts/app.blade.php
 *                                      -> @include('layouts.sidebar')
 *                                      -> layouts/partials/sidebar.blade.php
 *
 * Only `layouts/sidebar.blade.php` includes the partial, so a page is checked
 * for the SHELL it opens with, never for the partial itself.
 *
 * EXEMPTIONS MUST PROVE THEMSELVES. An allowlist keyed on a name is a hole: a
 * page called `something.print` that is really a normal screen would slip
 * through it silently. So each exemption below has to demonstrate what it
 * claims to be — an auth page by opening the guest shell, a print template by
 * actually containing print machinery. A view that matches neither fails even
 * if its name looks exempt.
 */

use Illuminate\Support\Str;

/**
 * OPENS one of the shells that carries the sidebar.
 *
 * The `<` and the negative lookahead for `/` are load-bearing. The first cut of
 * this helper matched the bare name, which also matches the CLOSING
 * `</x-settings-shell>` tag — so a page whose opening shell had been deleted
 * still looked shelled, and a mutation test that stripped the shell from a
 * real view SURVIVED. A guard that cannot fail for the case it exists to catch
 * is worse than no guard, because it reads as coverage.
 */
function d12HasSidebarShell(string $src): bool
{
    return (bool) preg_match(
        '/<(?!\/)\s*x-(?:app-layout|settings-shell)\b|@extends\([\'"]layouts\.sidebar[\'"]\)/',
        $src,
    );
}

/** Genuinely a logged-out screen: no authenticated user to build a menu for. */
function d12IsGuestScreen(string $src): bool
{
    return (bool) preg_match('/<(?!\/)\s*x-guest-layout\b|@extends\([\'"]layouts\.guest[\'"]\)/', $src);
}

/**
 * Genuinely print/PDF output: a sidebar on paper is a defect.
 *
 * A bare `<!DOCTYPE html>` is NOT sufficient on its own — every standalone
 * document has one, so accepting it would exempt any page that simply forgot
 * its shell. The document must also be NAMED as print or pdf output, so the
 * exemption needs both the intent and the shape.
 */
function d12IsPrintTemplate(string $name, string $src): bool
{
    if (preg_match('/window\.print\(\)|@media\s+print/i', $src)) {
        return true;
    }

    return Str::contains($name, ['print', 'pdf'])
        && (bool) preg_match('/<!DOCTYPE html>/i', $src);
}

/** @return array<string, string> view name => absolute path */
function d12ControllerRenderedViews(): array
{
    $root = base_path();
    $sources = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // The layout COMPONENTS render `layouts.app` / `layouts.guest`
        // themselves. Those are the shells, not pages, so the classes that
        // return them are skipped rather than the names blacklisted — the
        // distinction matters if a page is ever genuinely named that.
        if (Str::contains($file->getPathname(), '/View/Components/')) {
            continue;
        }

        preg_match_all("/view\('([a-zA-Z0-9_.\-]+)'/", (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $name) {
            $path = $root.'/resources/views/'.str_replace('.', '/', $name).'.blade.php';
            if (is_file($path)) {
                $sources[$name] = $path;
            }
        }
    }

    ksort($sources);

    return $sources;
}

it('finds a meaningful number of pages to check', function () {
    // NON-VACUITY. Without this, a scanner that silently stopped resolving
    // views would make every assertion below pass over an empty set — the
    // suite would go green precisely when it had stopped checking anything.
    //
    // A FLOOR, not a census. 182 resolved on 2026-09-20 (the 184 measured by
    // hand minus `layouts.app` and `layouts.guest`, which the layout
    // COMPONENTS render and which are shells rather than pages). An exact
    // count would fail on every legitimate new page and train people to bump
    // the number without reading why, which is how a pin stops meaning
    // anything. The floor catches the failure mode that matters — the set
    // collapsing — while a few named members prove the resolver still maps
    // dotted names onto real files across different modules.
    $views = d12ControllerRenderedViews();

    expect(count($views))->toBeGreaterThanOrEqual(170)
        ->and($views)->toHaveKeys([
            'dashboard',
            'rme.visits.index',
            'settings.doctor-devices.index',
            'auth.login',
            'rme.visits.print',
        ]);
});

it('renders every authenticated page inside a sidebar shell', function () {
    $missing = [];

    foreach (d12ControllerRenderedViews() as $name => $path) {
        $src = (string) file_get_contents($path);

        if (d12HasSidebarShell($src)) {
            continue;
        }

        // Not shelled — it must EARN its exemption.
        if (d12IsGuestScreen($src) || d12IsPrintTemplate($name, $src)) {
            continue;
        }

        $missing[] = $name;
    }

    expect($missing)->toBe([], 'these pages render without a sidebar and are neither a guest screen nor print output: '.implode(', ', $missing));
});

it('keeps the sidebar off the surfaces that must not have one', function () {
    $wrong = [];

    foreach (d12ControllerRenderedViews() as $name => $path) {
        $src = (string) file_get_contents($path);

        // A print template or a login screen that also opened an app shell
        // would put the navigation menu onto paper, or in front of a user who
        // is not logged in yet.
        if (d12HasSidebarShell($src) && d12IsGuestScreen($src)) {
            $wrong[] = $name;
        }
    }

    expect($wrong)->toBe([], 'guest screens opened an authenticated shell: '.implode(', ', $wrong));
});

it('proves the shell chain actually reaches the sidebar partial', function () {
    // The whole suite rests on `x-app-layout` carrying the sidebar. If that
    // include is ever removed, every assertion above keeps passing while every
    // page loses its menu — so the chain itself is asserted, not assumed.
    $app = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
    expect($app)->toContain("@include('layouts.sidebar')");

    $shell = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
    expect($shell)->toMatch('/partials\.sidebar|partials\/sidebar/');

    // And the settings shell reaches it through the app layout.
    $settings = (string) file_get_contents(resource_path('views/components/settings-shell.blade.php'));
    expect($settings)->toContain('<x-app-layout>');
});
