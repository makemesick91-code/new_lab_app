<x-settings-shell title="Preview FHIR Lokal — SATUSEHAT">
    <x-ui.page-header
        title="Preview FHIR Lokal — Kandidat #{{ $candidate->id }}"
        subtitle="Dibangun dari data lokal + mapping aktif. Odontogram, scan, dan tulisan tangan tidak disertakan.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('satusehat.submissions.show', $candidate)">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert variant="info" title="PREVIEW LOKAL — BELUM DIKIRIM KE SATUSEHAT">
        {{ $preview['note'] }} Lingkungan: <strong>{{ $preview['environment'] }}</strong>.
        Validasi lokal tidak menjamin acceptance oleh API SATUSEHAT.
    </x-ui.alert>

    @if ($satusehat2Watch ?? true)
        <x-ui.alert variant="warning" title="SATUSEHAT-2 MASIH WATCH">
            Kredensial sandbox belum tersedia — pengiriman eksternal nonaktif.
        </x-ui.alert>
    @endif

    {{-- SATUSEHAT-3 — dental (odontogram) local FHIR preview. --}}
    <x-ui.card class="mt-4" title="Preview Gigi (Odontogram) — Lokal">
        <p class="mb-3 text-sm text-ink-muted">
            Observation gigi dibentuk dari odontogram terstruktur + mapping resmi yang aktif.
            Gambar odontogram, scan, dan tulisan tangan tidak pernah disertakan.
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-hairline text-left text-ink-soft">
                        <th class="py-1 pr-3">#</th>
                        <th class="py-1 pr-3">Variabel</th>
                        <th class="py-1 pr-3">Status</th>
                        <th class="py-1 pr-3">Confidence</th>
                        <th class="py-1 pr-3">Gigi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dentalPreview['resources'] as $resource)
                        <tr class="border-b border-hairline/60">
                            <td class="py-1 pr-3">{{ $resource['order'] }}</td>
                            <td class="py-1 pr-3">{{ $resource['variable'] }}</td>
                            <td class="py-1 pr-3">
                                <x-ui.badge :tone="($resource['supported'] ?? false) ? 'success' : 'warning'">
                                    {{ ($resource['supported'] ?? false) ? 'Didukung' : 'Belum' }}
                                </x-ui.badge>
                            </td>
                            <td class="py-1 pr-3 text-xs text-ink-muted">{{ $resource['mapping_confidence'] ?? '—' }}</td>
                            <td class="py-1 pr-3 text-xs">{{ $resource['tooth_number'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @foreach ($preview['resources'] as $resource)
        <x-ui.card class="mt-4" :title="$resource['resource_type']">
            <div class="mb-2 flex items-center gap-2">
                <x-ui.badge :tone="$resource['supported'] ? 'success' : 'neutral'">
                    {{ $resource['supported'] ? 'Didukung' : 'Belum didukung' }}
                </x-ui.badge>
                <span class="text-xs text-ink-muted">Urutan dependensi: {{ $resource['order'] }}</span>
            </div>

            @if (! empty($resource['issues']))
                <ul class="mb-2 list-disc pl-5 text-sm text-warning-700">
                    @foreach ($resource['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($resource['payload'])
                <pre class="overflow-x-auto rounded-xl bg-navy-50 p-3 text-xs text-ink">{{ json_encode($resource['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @if (($resource['payload_hash'] ?? null))
                    <p class="mt-1 text-xs text-ink-muted">Hash payload: {{ $resource['payload_hash'] }}</p>
                @endif
            @else
                <p class="text-sm text-ink-muted">Tidak ada payload untuk resource ini.</p>
            @endif
        </x-ui.card>
    @endforeach
</x-settings-shell>
