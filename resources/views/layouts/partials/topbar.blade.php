@php
    $user = auth()->user();
@endphp

<header
    class="sticky top-0 z-30 flex w-full border-b border-hairline bg-white"
    x-data="{ profileOpen: false }"
>
    <div class="flex w-full items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-hairline text-ink-soft hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 xl:hidden"
                @click="$store.sidebar.toggleMobileOpen()"
                aria-label="Buka menu navigasi"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <button
                type="button"
                data-testid="sidebar-collapse-toggle"
                class="hidden h-10 w-10 items-center justify-center rounded-lg border border-hairline text-ink-soft hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 xl:inline-flex"
                @click="$store.sidebar.toggleExpanded()"
                :aria-expanded="$store.sidebar.isExpanded"
                :aria-label="$store.sidebar.isExpanded ? 'Sembunyikan sidebar' : 'Tampilkan sidebar'"
                :title="$store.sidebar.isExpanded ? 'Sembunyikan sidebar' : 'Tampilkan sidebar'"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" x-show="$store.sidebar.isExpanded" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" x-show="! $store.sidebar.isExpanded" />
                </svg>
            </button>

            <div class="min-w-0 xl:hidden">
                <p class="truncate text-sm font-semibold text-navy">DaengtisiaMS</p>
                <p class="truncate text-xs text-ink-soft">Klinik Gigi Daengtisia</p>
            </div>

            @isset($header)
                <div class="hidden min-w-0 xl:block">
                    {{ $header }}
                </div>
            @endisset
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @include('layouts.partials.rme-online-context-badge')

            <div class="hidden text-right sm:block">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Daengtisia Management System</p>
                <p class="text-sm font-medium text-navy">{{ $user?->name }}</p>
            </div>

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-hairline bg-white px-3 py-2 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                            {{ strtoupper(substr($user?->name ?? 'U', 0, 1)) }}
                        </span>
                        <span class="hidden sm:inline">{{ $user?->name }}</span>
                        <svg class="h-4 w-4 text-ink-muted" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="px-4 py-2 border-b border-hairline sm:hidden">
                        <p class="text-sm font-medium text-navy">{{ $user?->name }}</p>
                        <p class="text-xs text-ink-soft">{{ $user?->email }}</p>
                    </div>
                    <x-dropdown-link :href="route('profile.edit')">
                        Profil
                    </x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                            Logout
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>

    @isset($header)
        <div class="border-t border-hairline px-4 py-4 sm:px-6 lg:px-8 xl:hidden">
            {{ $header }}
        </div>
    @endisset
</header>
