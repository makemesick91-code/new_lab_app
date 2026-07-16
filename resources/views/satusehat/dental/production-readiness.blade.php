{{-- SATUSEHAT-3 — read-only production readiness. Production stays blocked. --}}
<x-settings-shell title="SATUSEHAT — Kesiapan Produksi">
    <x-ui.page-header
        title="Kesiapan Produksi SATUSEHAT"
        subtitle="Read-only. Aktivasi produksi tetap terblokir sampai sprint aktivasi terpisah.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if ($satusehat2Watch)
        <x-ui.alert variant="warning" title="SATUSEHAT-2 MASIH WATCH — KREDENSIAL SANDBOX BELUM TERSEDIA">
            Round-trip sandbox belum terverifikasi. Pengiriman eksternal dan produksi tetap nonaktif.
        </x-ui.alert>
    @endif

    <x-ui.alert variant="{{ $report['production_allowed'] ? 'danger' : 'info' }}" title="Status Aktivasi Produksi">
        Aktivasi produksi: <strong>{{ $report['production_allowed'] ? 'DIIZINKAN (tidak diharapkan!)' : 'TERBLOKIR' }}</strong>.
        Lingkungan: <strong>{{ $report['environment'] }}</strong>.
    </x-ui.alert>

    <x-ui.card class="mt-4" title="Guard Aktivasi Produksi">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($report['guard']['checks'] as $c)
                        <tr class="border-b border-hairline/60">
                            <td class="py-1 pr-3">
                                <x-ui.badge :tone="$c['passed'] ? 'success' : 'neutral'">{{ $c['passed'] ? 'PASS' : 'BLOCK' }}</x-ui.badge>
                            </td>
                            <td class="py-1 pr-3">{{ $c['label'] }}</td>
                            <td class="py-1 pr-3 text-xs text-ink-muted">{{ $c['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card class="mt-4" title="Kategori Kesiapan (ringkasan: {{ json_encode($report['summary']) }})">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-hairline text-left text-ink-soft">
                        <th class="py-1 pr-3">Kategori</th>
                        <th class="py-1 pr-3">Jenis</th>
                        <th class="py-1 pr-3">Status</th>
                        <th class="py-1 pr-3">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['categories'] as $cat)
                        <tr class="border-b border-hairline/60">
                            <td class="py-1 pr-3">{{ $cat['label'] }}</td>
                            <td class="py-1 pr-3 text-xs uppercase text-ink-muted">{{ $cat['kind'] }}</td>
                            <td class="py-1 pr-3">
                                <x-ui.badge :tone="$cat['status'] === 'ready_internal' ? 'success' : ($cat['status'] === 'in_progress' ? 'warning' : 'neutral')">
                                    {{ $cat['status'] }}
                                </x-ui.badge>
                            </td>
                            <td class="py-1 pr-3 text-xs text-ink-muted">{{ $cat['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-settings-shell>
