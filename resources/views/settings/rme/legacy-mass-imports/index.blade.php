{{--
    FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1

    Thin wrapper. Both mass surfaces render identical markup and differ only in
    the $heading / $routePrefix / $importType the controller supplies, so the
    markup lives once in legacy-mass-shared rather than being copied and left to
    drift. Separate view directories are kept because the route prefixes are
    deliberately disjoint (§35).
--}}
@include('settings.rme.legacy-mass-shared.index')
