{{-- SATUSEHAT-3 — read-only dental coverage matrix. PII-free. --}}
<x-settings-shell title="SATUSEHAT — Cakupan Gigi">
    <x-ui.page-header
        title="Cakupan Use-Case Gigi (SATUSEHAT)"
        subtitle="Pemetaan variabel gigi lokal ke profil resmi Rawat Jalan Gigi. Read-only.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.alert variant="info" title="Sumber Profil Resmi">
        {{ $profile['name'] ?? 'Rawat Jalan Gigi' }} — {{ $profile['source_version'] ?? '' }}
        ({{ $profile['source_dated'] ?? '' }}). Diaudit: {{ $profile['audited_at'] ?? '' }}.
        Lingkungan: <strong>{{ $environment }}</strong>.
        Validasi lokal tidak menjamin acceptance oleh API SATUSEHAT.
    </x-ui.alert>

    <x-ui.card class="mt-4" title="Ringkasan Audit — keputusan: {{ $audit['decision'] }}">
        <p class="text-sm text-ink-muted">Cakupan: {{ json_encode($audit['coverage_summary']) }}</p>
        <p class="text-sm text-ink-muted">Mapping: {{ json_encode($audit['mapping_summary']) }}</p>
        @foreach ($audit['warnings'] as $w)
            <p class="text-xs text-warning-700">• {{ $w }}</p>
        @endforeach
        @foreach ($audit['errors'] as $e)
            <p class="text-xs text-danger-700">• {{ $e }}</p>
        @endforeach
    </x-ui.card>

    <x-ui.card class="mt-4" title="Matriks Cakupan Variabel Gigi">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-hairline text-left text-ink-soft">
                        <th class="py-1 pr-3">Variabel</th>
                        <th class="py-1 pr-3">Resource</th>
                        <th class="py-1 pr-3">Path FHIR</th>
                        <th class="py-1 pr-3">Sumber Lokal</th>
                        <th class="py-1 pr-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($coverage as $row)
                        <tr class="border-b border-hairline/60">
                            <td class="py-1 pr-3">{{ $row['label'] }}</td>
                            <td class="py-1 pr-3">{{ $row['resource'] }}</td>
                            <td class="py-1 pr-3 text-xs text-ink-muted">{{ $row['path'] }}</td>
                            <td class="py-1 pr-3 text-xs">{{ $row['local_source'] }}</td>
                            <td class="py-1 pr-3">
                                <x-ui.badge :tone="in_array($row['status'], ['supported','supported_with_mapping']) ? 'success' : (str_contains($row['status'], 'unsupported') ? 'danger' : 'warning')">
                                    {{ $row['status'] }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-settings-shell>
