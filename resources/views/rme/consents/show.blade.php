<x-settings-shell title="Persetujuan Tindakan Medis">
    <div class="space-y-6">
        <x-ui.page-header
            title="Persetujuan Tindakan Medis"
            :subtitle="$consent->consent_number.' — '.($consent->signed_at?->format('d/m/Y H:i') ?? '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.badge :tone="$consent->isVoided() ? 'danger' : 'success'">
                    {{ $consent->isVoided() ? 'Dibatalkan' : 'Ditandatangani' }}
                </x-ui.badge>

                {{-- Rule 243 §1 — a Doctor reads the PTM here but never prints it;
                     the server refuses the print route too. --}}
                @can('print', $consent)
                    <x-ui.button variant="secondary" :href="route('rme.consents.print', $consent)" target="_blank">
                        Cetak Persetujuan
                    </x-ui.button>
                @endcan

                @if ($consent->clinicVisit)
                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $consent->clinicVisit)">
                        &larr; Kembali ke Kunjungan
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if ($consent->isVoided())
            <x-ui.alert variant="danger" title="Persetujuan ini telah dibatalkan.">
                Persetujuan yang dibatalkan tidak lagi membuka pembayaran. Tanda tangan baru harus diambil
                untuk kunjungan ini.
                @if ($consent->void_reason)
                    <span class="block mt-1">Alasan: {{ $consent->void_reason }}</span>
                @endif
            </x-ui.alert>
        @else
            <x-ui.alert variant="success" title="Persetujuan tindakan medis sudah ditandatangani.">
                Pembayaran untuk kunjungan ini sudah dapat diproses oleh kasir.
            </x-ui.alert>
        @endif

        <x-ui.card>
            @include('rme.consents.partials.document-styles')
            @include('rme.consents.partials.document')
        </x-ui.card>

        {{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8) — supervised void; canVoid is
             decided in the controller (policy + maker ≠ checker), and the route,
             the request and the service decide again. --}}
        @if ($canVoid ?? false)
            <x-ui.card>
                <form method="POST" action="{{ route('rme.consents.void', $consent) }}" class="space-y-3" data-testid="consent-void-form">
                    @csrf
                    <label for="void_reason" class="block text-sm font-medium text-ink">Batalkan persetujuan ini (pengawas)</label>
                    <textarea id="void_reason" name="void_reason" rows="3" required minlength="10" maxlength="1000"
                              class="block w-full rounded-lg border-hairline text-sm">{{ old('void_reason') }}</textarea>
                    @error('void_reason')<p class="text-xs text-danger-700">{{ $message }}</p>@enderror
                    @error('consent')<p class="text-xs text-danger-700">{{ $message }}</p>@enderror
                    <p class="text-xs text-ink-muted">Persetujuan tidak dihapus; ia ditandai dibatalkan beserta alasan dan pengawasnya. Setelah itu persetujuan baru dapat ditandatangani selama pemeriksaan berlangsung.</p>
                    <x-ui.button type="submit" variant="danger">Batalkan Persetujuan</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card title="Jejak Dokumen">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Nomor Persetujuan</dt>
                    <dd class="mt-1 font-mono text-ink">{{ $consent->consent_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Versi Formulir</dt>
                    <dd class="mt-1 text-ink">{{ $consent->template_version }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Ditandatangani</dt>
                    <dd class="mt-1 text-ink">{{ $consent->signed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Dicatat oleh</dt>
                    <dd class="mt-1 text-ink">{{ $consent->signedBy?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Persetujuan Dokumentasi/Publikasi</dt>
                    <dd class="mt-1 text-ink">{{ $consent->documentation_consent ? 'YA' : 'TIDAK' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Cabang</dt>
                    <dd class="mt-1 text-ink">{{ $branch?->name ?? '—' }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-ink-muted">
                Persetujuan yang sudah ditandatangani bersifat tetap dan tidak dapat diubah. Bila ada kekeliruan,
                persetujuan dibatalkan dengan alasan dan tanda tangan baru diambil.
            </p>
        </x-ui.card>
    </div>
</x-settings-shell>
