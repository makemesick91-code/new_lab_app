<?php

/**
 * PWA-FOUNDATION-1 — installability metadata reaches every HTML shell, and the
 * offline fallback stays free of application data.
 */

use App\Models\User;

it('serves the manifest link on the unauthenticated shell', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('<meta name="theme-color" content="#2563EB">', false);
});

it('serves the manifest link on the authenticated shell', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('<meta name="theme-color" content="#2563EB">', false);
});

it('declares the Android and iOS standalone metadata exactly once', function () {
    $html = view('layouts.partials.pwa-head')->render();

    expect(substr_count($html, 'rel="manifest"'))->toBe(1)
        ->and(substr_count($html, 'name="theme-color"'))->toBe(1)
        ->and($html)->toContain('name="mobile-web-app-capable" content="yes"')
        ->and($html)->toContain('name="apple-mobile-web-app-capable" content="yes"')
        ->and($html)->toContain('name="apple-mobile-web-app-title" content="DaengtisiaMS"')
        ->and($html)->toContain('rel="apple-touch-icon"');
});

it('wires the partial into both canonical layouts', function () {
    foreach (['app', 'guest'] as $layout) {
        $source = (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));

        expect(substr_count($source, "@include('layouts.partials.pwa-head')"))
            ->toBe(1, "layouts/{$layout}.blade.php must include the PWA head exactly once");
    }
});

it('registers the service worker from the canonical Vite entry', function () {
    $entry = (string) file_get_contents(resource_path('js/app.js'));

    expect($entry)->toContain("import { bootPwa } from './pwa';")
        ->and($entry)->toContain('bootPwa();');

    // Progressive enhancement: no framework, no bundler plugin, no new dependency.
    $packageJson = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
    $dependencies = array_merge(
        array_keys($packageJson['dependencies'] ?? []),
        array_keys($packageJson['devDependencies'] ?? []),
    );

    foreach (['workbox-window', 'vite-plugin-pwa', 'react', 'vue', 'next'] as $forbidden) {
        expect($dependencies)->not->toContain($forbidden);
    }
});

it('ships a self-contained offline shell', function () {
    $path = public_path('offline.html');

    expect(is_file($path))->toBeTrue();

    $html = (string) file_get_contents($path);

    // It must render with nothing else cached, so it cannot depend on the build.
    expect($html)->not->toContain('@vite')
        ->and($html)->not->toContain('/build/')
        ->and($html)->toContain('Koneksi internet tidak tersedia.')
        ->and($html)->toContain('Coba Lagi');
});

it('keeps patient and transaction data out of the offline shell', function () {
    $html = strtolower((string) file_get_contents(public_path('offline.html')));

    // No data placeholders, no template engine, no client-side persistence, and
    // no queue for deferred clinical writes.
    foreach (['{{', '@php', 'localstorage', 'sessionstorage', 'indexeddb', 'fetch(', 'sync'] as $forbidden) {
        expect($html)->not->toContain($forbidden);
    }
})->group('security');
