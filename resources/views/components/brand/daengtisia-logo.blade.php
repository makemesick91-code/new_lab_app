@props([
    'pdf' => false,
    'alt' => 'Logo Klinik Gigi Daengtisia',
    'variant' => 'full',
])

{{--
    Official Daengtisia clinic logo.
    - Web / browser-print contexts: transparent PNG served via asset() URL.
    - PDF (Dompdf) context: pass :pdf="true". Dompdf's bundled CPDF backend needs
      the PHP GD extension to embed alpha-channel PNGs, which is not guaranteed on
      every host. To stay portable we embed a white-flattened JPEG (no alpha) as a
      base64 data URI instead, so the logo renders without GD or remote URL fetch.
    Aspect ratio (3:2) is preserved by callers using a single fixed dimension +
    auto; avoid forcing both a fixed width and height.
--}}
{{--
    variant="wordmark" — REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1: a
    tight crop of the SAME official artwork (mascot + DAENGTISIA + KLINIK GIGI,
    no tagline) for document headers such as the RME invoice, where the full
    3:2 canvas is mostly whitespace. It is a white-flattened JPEG, so it embeds
    in dompdf without GD and is served as-is in the browser. Nothing about the
    mark itself was redrawn.
--}}
@php
    $logoPng = public_path('assets/brand/daengtisia-logo.png');
    $logoJpg = public_path('assets/brand/daengtisia-logo.jpg');
    $wordmarkJpg = public_path('assets/brand/daengtisia-logo-wordmark.jpg');

    if ($variant === 'wordmark' && is_file($wordmarkJpg)) {
        $logoSrc = $pdf
            ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($wordmarkJpg))
            : asset('assets/brand/daengtisia-logo-wordmark.jpg');
    } elseif ($pdf && is_file($logoJpg)) {
        $logoSrc = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoJpg));
    } elseif ($pdf && is_file($logoPng)) {
        $logoSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPng));
    } else {
        $logoSrc = asset('assets/brand/daengtisia-logo.png');
    }
@endphp

<img src="{{ $logoSrc }}" alt="{{ $alt }}" {{ $attributes }} />
