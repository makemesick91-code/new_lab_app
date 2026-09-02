<x-settings-shell title="Detail Perangkat">
    @php
        $statusLabels = ['active' => 'Aktif', 'disabled' => 'Nonaktif', 'revoked' => 'Dicabut'];
        $statusTone = ['active' => 'success', 'disabled' => 'warning', 'revoked' => 'danger'];
        $canManage = auth()->user()?->can('manageLifecycle', $device) ?? false;
    @endphp

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">{{ $device->device_name }}</h2>
                <p class="text-sm text-ink-soft">{{ $device->branch?->name ?? '—' }}</p>
            </div>
            <x-ui.badge :tone="$statusTone[$device->status] ?? 'neutral'">{{ $statusLabels[$device->status] ?? $device->status }}</x-ui.badge>
        </div>

        <dl class="mt-5 grid gap-4 border-t border-hairline pt-5 md:grid-cols-3">
            <div><dt class="text-xs text-gray-500">Platform</dt><dd class="text-sm text-ink">{{ $device->platform ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Model</dt><dd class="text-sm text-ink">{{ $device->device_model ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Versi OS</dt><dd class="text-sm text-ink">{{ $device->os_version ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Versi Aplikasi</dt><dd class="text-sm text-ink">{{ $device->app_version ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Terakhir Terlihat</dt><dd class="text-sm text-ink">{{ $device->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Didaftarkan</dt><dd class="text-sm text-ink">{{ $device->registered_at?->format('d/m/Y H:i') ?? '—' }} @if ($device->registeredBy)· {{ $device->registeredBy->name }}@endif</dd></div>

            {{-- Only a short, safe identity summary. The full key fingerprint is
                 never sprayed across the admin UI. --}}
            <div>
                <dt class="text-xs text-gray-500">Identitas Perangkat</dt>
                <dd class="text-sm text-ink">
                    @if ($device->isCryptographicallyVerified())
                        <x-ui.badge tone="success">Terverifikasi kriptografis</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Belum terverifikasi</x-ui.badge>
                    @endif
                    @if ($device->shortFingerprint())
                        <span class="ml-2 font-mono text-xs text-ink-soft">{{ $device->shortFingerprint() }}…</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($device->isDisabled() && $device->disabled_reason)
            <x-ui.alert variant="warning" class="mt-4">
                Dinonaktifkan: {{ $device->disabled_reason }}
                @if ($device->disabledBy)· oleh {{ $device->disabledBy->name }}@endif
            </x-ui.alert>
        @endif

        @if ($device->isRevoked())
            <x-ui.alert variant="danger" class="mt-4">
                <strong>Perangkat dicabut permanen.</strong> {{ $device->revoked_reason }}
                @if ($device->revokedBy)· oleh {{ $device->revokedBy->name }}@endif
                <br>Identitas ini tidak dapat diaktifkan kembali. Untuk memakai ulang perangkat fisik,
                daftarkan identitas perangkat baru melalui enrolment aplikasi Android.
            </x-ui.alert>
        @endif
    </x-ui.card>

    @if ($canManage && ! $device->isRevoked())
        <x-ui.card class="mt-5" x-data="{ confirmRevoke: false }">
            <h3 class="text-sm font-semibold text-ink">Tindakan Perangkat</h3>

            <div class="mt-4 flex flex-wrap items-start gap-3">
                <x-ui.button variant="secondary" size="sm" :href="route('settings.doctor-devices.edit', $device)">Ubah Data</x-ui.button>

                @if ($device->isActive())
                    <form method="POST" action="{{ route('settings.doctor-devices.disable', $device) }}" class="flex items-end gap-2">
                        @csrf
                        <x-ui.input name="reason" label="Alasan nonaktif" required placeholder="Alasan" />
                        <x-ui.button type="submit" variant="warning" size="sm">Nonaktifkan</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('settings.doctor-devices.reactivate', $device) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success" size="sm">Aktifkan Kembali</x-ui.button>
                    </form>
                @endif
            </div>

            {{-- Revoke is terminal, so it is deliberately high friction: an
                 explicit reveal, an explicit reason, and an explicit confirm. --}}
            <div class="mt-6 border-t border-hairline pt-5">
                <x-ui.button variant="danger" size="sm" type="button" x-on:click="confirmRevoke = !confirmRevoke">
                    Cabut Perangkat (Revoke)
                </x-ui.button>

                <div x-show="confirmRevoke" x-cloak class="mt-3">
                    <x-ui.alert variant="danger">
                        Tindakan ini <strong>permanen</strong>. Perangkat yang dicabut tidak dapat diaktifkan kembali.
                    </x-ui.alert>
                    <form method="POST" action="{{ route('settings.doctor-devices.revoke', $device) }}" class="mt-3 flex items-end gap-2">
                        @csrf
                        <x-ui.input name="reason" label="Alasan pencabutan" required placeholder="Contoh: perangkat hilang" />
                        <x-ui.button type="submit" variant="danger" size="sm"
                                     x-on:click="return confirm('Cabut perangkat ini secara permanen?')">
                            Konfirmasi Cabut
                        </x-ui.button>
                    </form>
                </div>
            </div>
        </x-ui.card>
    @endif

    <div class="mt-5">
        <x-ui.button variant="ghost" :href="route('settings.doctor-devices.index')">Kembali ke Daftar Perangkat</x-ui.button>
    </div>
</x-settings-shell>
