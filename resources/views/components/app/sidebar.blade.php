@props([
    'brand' => config('app.name', 'DaengtisiaMS'),
])

{{--
    UIX-1 reusable branded sidebar frame (luxury healthcare shell).
    White surface, navy text, blue active state + subtle gold accent driven by
    .menu-item* token classes (resources/css/app.css). Available for future layout
    adoption; the live layout currently uses layouts/sidebar.blade.php.
    Slots: default = nav items (use .menu-item / .menu-item-active token classes).
--}}
<aside {{ $attributes->merge(['class' => 'flex h-full w-[290px] flex-col border-r border-hairline bg-surface']) }}>
    <div class="flex items-center gap-2 border-b border-hairline px-5 py-4">
        <span class="text-lg font-extrabold tracking-tight text-navy">{{ $brand }}</span>
    </div>
    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Sidebar">
        {{ $slot }}
    </nav>
    @isset($footer)
        <div class="border-t border-hairline px-4 py-3 text-xs text-ink-soft">{{ $footer }}</div>
    @endisset
</aside>
