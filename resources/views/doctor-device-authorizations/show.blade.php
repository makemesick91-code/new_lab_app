{{-- Review one doctor/device pairing.

     Shows exactly what an approval decision needs: who, which tablet, which
     branch, when, and a truncated key fingerprint for identification. Never a
     private key, never a pairing code, never a reusable secret, never a KTP or
     NIK, never anything clinical. --}}
<x-settings-shell title="Tinjau Akses Perangkat Dokter">
    @php
        $statusLabels = [
            'pending' => 'Menunggu Persetujuan',
            'active' => 'Disetujui',
            'rejected' => 'Ditolak',
            'revoked' => 'Dicabut',
        ];
        $statusTone = [
            'pending' => 'warning',
            'active' => 'success',
            'rejected' => 'danger',
            'revoked' => 'danger',
        ];
        $device = $authorization->device;
        $canDecide = auth()->user()?->can('decide', $authorization) ?? false;
    @endphp

    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">{{ $authorization->doctor?->name ?? '—' }}</h2>
                <p class="text-sm text-ink-soft">{{ $device?->device_name ?? '—' }} · {{ $device?->branch?->name ?? '—' }}</p>
            </div>
            <x-ui.badge :tone="$statusTone[$authorization->status] ?? 'neutral'">{{ $statusLabels[$authorization->status] ?? $authorization->status }}</x-ui.badge>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-ink-muted">Platform / Model</dt><dd class="text-ink">{{ $device?->platform ?? '—' }} / {{ $device?->device_model ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Versi OS / Aplikasi</dt><dd class="text-ink">{{ $device?->os_version ?? '—' }} / {{ $device?->app_version ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Sidik Kunci</dt><dd class="font-mono text-ink">{{ $device?->shortFingerprint() ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Status Perangkat</dt><dd class="text-ink">{{ $device?->status ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Diminta</dt><dd class="text-ink">{{ $authorization->requested_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Sumber Permintaan</dt><dd class="text-ink">{{ $authorization->request_source }}</dd></div>
            @if ($authorization->approved_at)
                <div><dt class="text-ink-muted">Disetujui</dt><dd class="text-ink">{{ $authorization->approved_at->format('d/m/Y H:i') }} · {{ $authorization->approvedBy?->name ?? '—' }}</dd></div>
            @endif
            @if ($authorization->rejected_at)
                <div><dt class="text-ink-muted">Ditolak</dt><dd class="text-ink">{{ $authorization->rejected_at->format('d/m/Y H:i') }} · {{ $authorization->rejectedBy?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Alasan Penolakan</dt><dd class="text-ink">{{ $authorization->rejected_reason }}</dd></div>
            @endif
            @if ($authorization->revoked_at)
                <div><dt class="text-ink-muted">Dicabut</dt><dd class="text-ink">{{ $authorization->revoked_at->format('d/m/Y H:i') }} · {{ $authorization->revokedBy?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Alasan Pencabutan</dt><dd class="text-ink">{{ $authorization->revoked_reason }}</dd></div>
            @endif
            @if ($authorization->re_request_allowed_at)
                <div><dt class="text-ink-muted">Diizinkan Ajukan Ulang</dt><dd class="text-ink">{{ $authorization->re_request_allowed_at->format('d/m/Y H:i') }} · {{ $authorization->reRequestAllowedBy?->name ?? '—' }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($canDecide)
        <x-ui.card>
            <h3 class="text-sm font-semibold text-ink">Keputusan</h3>

            @if ($authorization->isPending())
                <p class="mt-1 text-xs text-ink-soft">
                    Menyetujui juga mengaktifkan perangkat ini di registri bila statusnya masih
                    <strong>Menunggu Persetujuan</strong> — satu keputusan untuk dokter dan perangkat sekaligus.
                </p>

                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <form method="POST" action="{{ route('doctor-device-authorizations.approve', $authorization) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success" size="sm">Setujui</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('doctor-device-authorizations.reject', $authorization) }}" class="flex items-end gap-2">
                        @csrf
                        <x-ui.input name="reason" label="Alasan penolakan" placeholder="Alasan" required />
                        <x-ui.button type="submit" variant="danger" size="sm">Tolak</x-ui.button>
                    </form>
                </div>
            @elseif ($authorization->isActive())
                <form method="POST" action="{{ route('doctor-device-authorizations.revoke', $authorization) }}" class="mt-4 flex items-end gap-2">
                    @csrf
                    <x-ui.input name="reason" label="Alasan pencabutan" placeholder="Alasan" required />
                    <x-ui.button type="submit" variant="danger" size="sm">Cabut Akses</x-ui.button>
                </form>
            @elseif ($authorization->isRejected())
                <p class="mt-1 text-xs text-ink-soft">
                    Permintaan yang ditolak <strong>tidak terbuka kembali dengan sendirinya</strong>.
                    Izinkan pengajuan ulang bila dokter ini memang boleh mencoba lagi dari perangkat tersebut.
                </p>
                <form method="POST" action="{{ route('doctor-device-authorizations.allow-re-request', $authorization) }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">Izinkan Ajukan Ulang</x-ui.button>
                </form>
            @else
                <p class="mt-1 text-xs text-ink-soft">
                    Otorisasi yang sudah dicabut bersifat final. Diperlukan permintaan baru dari perangkat.
                </p>
            @endif
        </x-ui.card>
    @endif

    <div class="mt-4">
        <x-ui.button variant="secondary" size="sm" :href="route('doctor-device-authorizations.index')">Kembali ke Daftar</x-ui.button>
    </div>
</x-settings-shell>
