@props([
    'title' => null,
])

{{--
    UIX-1 reusable branded topbar frame (luxury healthcare shell).
    Slots: branch = branch selector area, search = search/quick action,
    user = user/account area, default = extra items. Available for future layout
    adoption; the live layout currently uses layouts/partials/topbar.blade.php.
--}}
<header {{ $attributes->merge(['class' => 'sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-hairline bg-surface/95 px-4 py-3 backdrop-blur sm:px-6']) }}>
    <div class="flex min-w-0 items-center gap-3">
        {{ $slot }}
        @if ($title)
            <span class="truncate text-sm font-semibold text-navy">{{ $title }}</span>
        @endif
        @isset($branch)
            <div class="min-w-0">{{ $branch }}</div>
        @endisset
    </div>

    <div class="flex items-center gap-3">
        @isset($search)
            <div class="hidden sm:block">{{ $search }}</div>
        @endisset
        @isset($user)
            <div>{{ $user }}</div>
        @endisset
    </div>
</header>
