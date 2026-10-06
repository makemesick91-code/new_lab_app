{{-- FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — console sub-navigation. Presentation
     only: every route behind it is independently permission-gated. --}}
@php
    $tabs = [
        ['route' => 'developer-console.index', 'match' => 'developer-console.index', 'label' => 'Overview'],
        ['route' => 'developer-console.live-users', 'match' => 'developer-console.live-users', 'label' => 'Live Users'],
        ['route' => 'developer-console.errors', 'match' => 'developer-console.errors*', 'label' => 'Errors'],
        ['route' => 'developer-console.slow-requests', 'match' => 'developer-console.slow-requests', 'label' => 'Slow Requests'],
        ['route' => 'developer-console.slow-queries', 'match' => 'developer-console.slow-queries', 'label' => 'Slow Queries'],
        ['route' => 'developer-console.cache', 'match' => 'developer-console.cache', 'label' => 'Cache / Redis'],
        ['route' => 'developer-console.diagnostics', 'match' => 'developer-console.diagnostics', 'label' => 'Diagnostik'],
    ];
@endphp
<nav aria-label="Navigasi Observability Console" class="ui-card overflow-x-auto p-1">
    <ul class="flex min-w-max gap-1 text-sm">
        @foreach ($tabs as $tab)
            @php $active = request()->routeIs($tab['match']); @endphp
            <li>
                <a href="{{ route($tab['route']) }}"
                   @if ($active) aria-current="page" @endif
                   class="block rounded-lg px-3 py-2 font-medium {{ $active ? 'bg-brand-50 text-brand-700' : 'text-ink-soft hover:bg-navy-50 hover:text-navy' }}">
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
