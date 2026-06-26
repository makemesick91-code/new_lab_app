@props([
    'pdf' => false,
    'alt' => 'Logo Klinik Gigi Daengtisia',
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
@php
    $logoPng = public_path('assets/brand/daengtisia-logo.png');
    $logoJpg = public_path('assets/brand/daengtisia-logo.jpg');

    if ($pdf && is_file($logoJpg)) {
        $logoSrc = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoJpg));
    } elseif ($pdf && is_file($logoPng)) {
        $logoSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPng));
    } else {
        $logoSrc = asset('assets/brand/daengtisia-logo.png');
    }
@endphp

<img src="{{ $logoSrc }}" alt="{{ $alt }}" {{ $attributes }} />
