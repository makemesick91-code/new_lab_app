<x-settings-shell title="Alur PR Cabang">
    @php
        $PR = \App\Modules\Inventory\Models\PurchaseRequest::class;

        $board = $board ?? [];
        $typeLabel = fn ($pr) => match ($pr->pr_type) {
            $PR::PR_TYPE_DARURAT => 'Darurat',
            $PR::PR_TYPE_REGULER => 'Reguler',
            default => 'Reguler',
        };
        $typeTone = fn ($pr) => $pr->pr_type === $PR::PR_TYPE_DARURAT ? 'danger' : 'info';

        // SPRINT-68.45 Scope B — truthful workflow-status badge mapped to real PR
        // statuses (no invented status). Approved + linked PO => "Terhubung PO".
        $statusBadge = function ($pr) use ($PR) {
            return match ($pr->status) {
                $PR::STATUS_DRAFT => ['Draf', 'neutral'],
                $PR::STATUS_SUBMITTED => ['Menunggu Warehouse', 'warning'],
                $PR::STATUS_REJECTED => ['Ditolak', 'danger'],
                $PR::STATUS_CANCELLED => ['Dibatalkan', 'neutral'],
                $PR::STATUS_APPROVED => ($pr->purchase_orders_count ?? 0) > 0
                    ? ['Terhubung PO', 'success']
                    : ['Selesai', 'success'],
                default => [ucfirst((string) $pr->status), 'info'],
            };
        };

        $emergencyQueue = $board['emergency_processing'] ?? collect();
        $regularQueue = $board['regular_processing'] ?? collect();
        $draftQueue = $board['drafts'] ?? collect();
        $pendingWarehouse = $emergencyQueue->count() + $regularQueue->count();
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

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-workflow-kpis>
            <x-ui.kpi-card label="PR Darurat (antrian)" :value="format_number_id($emergencyQueue->count())" />
            <x-ui.kpi-card label="PR Reguler (antrian)" :value="format_number_id($regularQueue->count())" />
            <x-ui.kpi-card label="Menunggu Warehouse" :value="format_number_id($pendingWarehouse)" />
            <x-ui.kpi-card label="Draf PR Cabang" :value="format_number_id($draftQueue->count())" />
        </div>

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
                'statusBadge' => $statusBadge,
                'canProcessPr' => $canProcessPr,
            ])
        </x-ui.card>

        <x-ui.card title="Antrian Proses Gudang — PR Reguler">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['regular_processing'] ?? collect(),
                'emptyText' => 'Tidak ada PR Reguler menunggu proses.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'statusBadge' => $statusBadge,
                'canProcessPr' => $canProcessPr,
            ])
        </x-ui.card>

        <x-ui.card title="Draf PR Cabang">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['drafts'] ?? collect(),
                'emptyText' => 'Belum ada draf PR.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'statusBadge' => $statusBadge,
                'canProcessPr' => false,
            ])
        </x-ui.card>

        <x-ui.card title="Riwayat Terbaru (Disetujui / Ditolak)">
            @include('inventory.purchase-requests.partials.workflow-list', [
                'rows' => $board['recent_completed'] ?? collect(),
                'emptyText' => 'Belum ada PR yang selesai diproses.',
                'typeLabel' => $typeLabel,
                'typeTone' => $typeTone,
                'statusBadge' => $statusBadge,
                'canProcessPr' => false,
            ])
        </x-ui.card>
    </div>
</x-settings-shell>
