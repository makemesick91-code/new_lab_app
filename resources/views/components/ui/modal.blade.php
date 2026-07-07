@props([
    'title' => null,
    'maxWidth' => 'max-w-lg',
])

{{--
    Self-contained accessible modal (Alpine). Provide a `trigger` slot to open it;
    body via default slot, optional `footer` slot.
    Example:
      <x-ui.modal title="Konfirmasi">
        <x-slot:trigger><x-ui.button>Buka</x-ui.button></x-slot:trigger>
        Isi modal...
        <x-slot:footer><x-ui.button variant="secondary" @click="open=false">Batal</x-ui.button></x-slot:footer>
      </x-ui.modal>
--}}
<div x-data="{ open: false }" {{ $attributes }}>
    @isset($trigger)
        <div @click="open = true">{{ $trigger }}</div>
    @endisset

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            @keydown.escape.window="open = false"
        >
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-navy/40" @click="open = false"></div>

            <div
                x-show="open"
                x-transition
                class="relative w-full {{ $maxWidth }} rounded-xl border border-hairline bg-surface shadow-card-hover"
                @click.stop
            >
                <div class="flex items-start justify-between gap-3 border-b border-hairline px-5 py-4">
                    <h3 class="text-base font-semibold text-navy">{{ $title }}</h3>
                    <button type="button" class="rounded p-1 text-ink-soft hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500" @click="open = false" aria-label="Tutup">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                    </button>
                </div>

                <div class="px-5 py-4 text-sm text-navy">{{ $slot }}</div>

                @isset($footer)
                    <div class="flex items-center justify-end gap-2 border-t border-hairline px-5 py-3">{{ $footer }}</div>
                @endisset
            </div>
        </div>
    </template>
</div>
