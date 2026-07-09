<x-settings-shell title="Alur PR Cabang">
    @php
        $board = $board ?? [];
        $typeLabel = fn ($pr) => match ($pr->pr_type) {
            \App\Modules\Inventory\Models\PurchaseRequest::PR_TYPE_DARURAT => 'Darurat',
            \App\Modules\Inventory\Models\PurchaseRequest::PR_TYPE_REGULER => 'Reguler',
            default => 'Reguler',
        };
        $typeTone = fn ($pr) => $pr->pr_type === \App\Modules\Inventory\Models\PurchaseRequest::PR_TYPE_DARURAT ? 'danger' : 'info';
    @endphp

    <div class="space-y-6">
        <x-ui.page-header title="Alur PR Cabang" subtitle="Permintaan pembelian dari Kepala Cabang ke Admin Gudang (Warehouse). Reguler & Darurat.">
            <x-slot:breadcrumb>Pengadaan</x-slot:breadcrumb>
            <x-slot:actions>
                @if ($canCreatePr)
                    <x-ui.button variant="primary" :href="route('inventory.purchase-requests.create', ['pr_type' => 'reguler'])">+ PR Reguler</x-ui.button>
                    <x-ui.button variant="warning" :href="route('inventory.purchase-requests.create', ['pr_type' => 'darurat'])">+ PR Darurat</x-ui.button>
                @endif
                <x-ui.button variant="secondary" :href="route('inventory.purchase-requests.index')">Semua PR</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            Kepala Cabang membuat <span class="font-semibold">PR (Reguler / Darurat)</span> saja — pembuatan Purchase Order ke vendor dilakukan oleh Admin Gudang melalui alur pengadaan yang ada. Semua data di halaman ini dibatasi pada cabang Anda.
        </x-ui.alert>

        {{-- Warehouse processing queue — emergency first --}}
        <x-ui.card title="Antrian Proses Gudang — PR Darurat">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['emergency_processing'] ?? collect(),
                'emptyText' => 'Tidak ada PR Darurat menunggu proses.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'canProcessPr' => $canProcessPr,
            ])
        </x-ui.card>

        <x-ui.card title="Antrian Proses Gudang — PR Reguler">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['regular_processing'] ?? collect(),
                'emptyText' => 'Tidak ada PR Reguler menunggu proses.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'canProcessPr' => $canProcessPr,
            ])
        </x-ui.card>

        <x-ui.card title="Draf PR Cabang">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['drafts'] ?? collect(),
                'emptyText' => 'Belum ada draf PR.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'canProcessPr' => false,
            ])
        </x-ui.card>

        <x-ui.card title="Riwayat Terbaru (Disetujui / Ditolak)">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['recent_completed'] ?? collect(),
                'emptyText' => 'Belum ada PR yang selesai diproses.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'canProcessPr' => false,
            ])
        </x-ui.card>
    </div>
</x-settings-shell>
