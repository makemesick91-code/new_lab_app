<x-settings-shell title="Arsip RME Lama">
    <x-ui.page-header title="Arsip RME Lama" :subtitle="($record->patient?->name ?? '-').' · '.($record->patient?->medical_record_number ?? '-')">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Rekam Medis Elektronik · Arsip RME Lama</x-slot:breadcrumb>
        <x-slot:actions>
            @if ($record->patient)
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.patients.show', $record->patient_id)">Riwayat RME Pasien</x-ui.button>
            @endif
            @if ($canExport)
                <x-ui.button variant="primary" size="sm" :href="route('oversight.legacy.rme.print', $record->id)" target="_blank">Cetak</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.legacy.rme.pdf', $record->id)">Unduh PDF</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.legacy.rme.source', $record->id)" target="_blank">PDF Sumber</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.alert variant="info">Dokumen historis yang diimpor dari arsip lama (PUBLISHED). Bukan kunjungan, tagihan, atau pembayaran pada sistem ini.</x-ui.alert>

    <x-ui.card title="Metadata Arsip" padding="p-5">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-ink-soft">Tanggal Dokumen</dt><dd class="text-navy">{{ $record->rme_date?->format('d-m-Y') ?? '-' }}@if ($record->latest_rme_date && $record->latest_rme_date->gt($record->rme_date)) – {{ $record->latest_rme_date->format('d-m-Y') }}@endif</dd></div>
            <div><dt class="text-xs text-ink-soft">Judul</dt><dd class="text-navy">{{ $record->title ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Cabang Asal</dt><dd class="text-navy">{{ $record->originBranch?->name ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Halaman</dt><dd class="text-navy">{{ $record->page_count }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Halaman">
        @if ($pages->isEmpty())
            <p class="text-sm text-ink-soft">Tidak ada halaman terender.</p>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($pages as $page)
                    <figure class="rounded-lg border border-hairline p-2">
                        <figcaption class="mb-2 text-xs text-ink-soft">Halaman {{ $page->page_number }} dari {{ $record->page_count }}</figcaption>
                        <img loading="lazy" class="w-full rounded border border-hairline bg-white" alt="Halaman {{ $page->page_number }}"
                             src="{{ route('oversight.legacy.rme.pages.show', [$record->id, $page->page_number]) }}">
                    </figure>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</x-settings-shell>
