{{--
    PWA-FOUNDATION-1 — installability metadata.

    Included by every canonical HTML shell (layouts/app + layouts/guest) so the
    manifest is discoverable both before and after login. The manifest, icons
    and service worker are served as static files from public/, outside the
    Laravel middleware stack, so they never carry a session cookie and are never
    subject to an authentication or online-context redirect.

    This partial adds metadata only. It grants no capability and changes no
    authentication, device-authorization or RBAC behaviour.
--}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#2563EB">
<meta name="application-name" content="DaengtisiaMS">

{{-- Android / Chromium standalone install. --}}
<meta name="mobile-web-app-capable" content="yes">

{{-- iOS home-screen fallback (Safari ignores the manifest for these). --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="DaengtisiaMS">
<link rel="apple-touch-icon" href="{{ asset('pwa/apple-touch-icon-180.png') }}">

<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('pwa/icon-192.png') }}">
