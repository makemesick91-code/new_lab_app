<?php

/**
 * PWA-FOUNDATION-1 — web app manifest contract.
 *
 * The manifest, its icons and the service worker are served as static files
 * from public/, deliberately outside the Laravel middleware stack, so these
 * assertions read the shipped bytes rather than a rendered response.
 */
it('ships a parseable web app manifest', function () {
    $path = public_path('manifest.webmanifest');

    expect(is_file($path))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest)->toBeArray();
});

it('declares the standalone installability contract', function () {
    $manifest = pwaManifest();

    expect($manifest['id'])->toBe('/')
        ->and($manifest['start_url'])->toBe('/')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['name'])->toBe('Daengtisia Management System')
        ->and($manifest['short_name'])->toBe('DaengtisiaMS')
        ->and($manifest['lang'])->toBe('id');
});

it('uses the canonical design-system colours', function () {
    $manifest = pwaManifest();

    // UIX-1 tokens: canvas off-white background, blue brand as the theme colour.
    // Gold is an accent token and must never become the application theme.
    expect($manifest['background_color'])->toBe('#F7F9FC')
        ->and($manifest['theme_color'])->toBe('#2563EB');
});

it('declares the icon sizes Android installability requires', function () {
    $sizes = collect(pwaManifest()['icons'])
        ->map(fn (array $icon) => $icon['purpose'].':'.$icon['sizes'])
        ->all();

    expect($sizes)->toContain('any:192x192')
        ->and($sizes)->toContain('any:512x512')
        ->and($sizes)->toContain('maskable:192x192')
        ->and($sizes)->toContain('maskable:512x512');
});

it('references icon files that exist at the declared dimensions', function () {
    foreach (pwaManifest()['icons'] as $icon) {
        $relative = ltrim((string) $icon['src'], '/');
        $path = public_path($relative);

        expect(is_file($path))->toBeTrue("missing icon: {$relative}");
        expect($icon['type'])->toBe('image/png');

        [$width, $height] = pwaPngDimensions($path);
        $declared = explode('x', (string) $icon['sizes']);

        expect("{$width}x{$height}")->toBe("{$declared[0]}x{$declared[1]}", "wrong dimensions: {$relative}");
    }
});

it('ships the iOS home-screen icon referenced by the layout', function () {
    $path = public_path('pwa/apple-touch-icon-180.png');

    expect(is_file($path))->toBeTrue();
    expect(pwaPngDimensions($path))->toBe([180, 180]);
});

/** Read the shipped manifest. */
function pwaManifest(): array
{
    return json_decode(
        (string) file_get_contents(public_path('manifest.webmanifest')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
}

/**
 * PNG dimensions straight from the IHDR chunk — the local PHP build has no GD
 * extension, so getimagesize() is not available for this assertion.
 */
function pwaPngDimensions(string $path): array
{
    $header = (string) file_get_contents($path, false, null, 0, 24);

    expect(substr($header, 0, 8))->toBe("\x89PNG\r\n\x1a\n", "not a PNG: {$path}");

    $parts = unpack('Nwidth/Nheight', substr($header, 16, 8));

    return [(int) $parts['width'], (int) $parts['height']];
}
