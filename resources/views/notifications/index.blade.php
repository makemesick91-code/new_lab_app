<x-settings-shell title="Notifikasi">
    <x-ui.page-header title="Notifikasi" subtitle="Pemberitahuan alur kerja lab dan sistem.">
        <x-slot:breadcrumb>Notifikasi</x-slot:breadcrumb>
        <x-slot:actions>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">Tandai Semua Terbaca ({{ $unreadCount }})</x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        @if ($notifications->isEmpty())
            <x-ui.empty-state title="Tidak ada notifikasi" description="Pemberitahuan alur kerja akan muncul di sini." />
        @else
            <ul class="divide-y divide-hairline">
                @foreach ($notifications as $notification)
                    <li class="flex items-start justify-between gap-3 py-3 {{ $notification->read_at ? 'opacity-70' : '' }}">
                        <div class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-hairline' : 'bg-brand-500' }}"></span>
                            <div>
                                <p class="text-sm font-medium text-ink">{{ $notification->data['title'] ?? 'Notifikasi' }}</p>
                                <p class="text-sm text-ink-soft">{{ $notification->data['message'] ?? '' }}</p>
                                <p class="mt-0.5 text-xs text-ink-muted">{{ $notification->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm">
                                {{ ($notification->data['url'] ?? null) ? 'Buka' : 'Tandai Terbaca' }}
                            </x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $notifications->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
