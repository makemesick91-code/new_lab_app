@php
    use App\Modules\Inventory\Enums\InventoryBatchActionType;
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
@endphp

<x-settings-shell title="Laporan Disposal & Adjustment Batch">
    <div class="space-y-6">
        <x-ui.page-header title="Laporan Disposal & Adjustment Batch"
            subtitle="Audit batch dari action log, permintaan disposal, approval, hingga movement ADJUSTMENT_OUT. Laporan read-only — tidak membuat atau mengubah stok.">
            <x-slot:breadcrumb>Inventory · Laporan · Disposal Batch</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('inventory.reports.batch-disposals.export', $filters)">Export CSV</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('inventory.reports.batch-disposals.print', $filters)" target="_blank" rel="noopener">Cetak</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('inventory.batch-disposal-requests.index')">Workflow Disposal</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.reports.batch-disposals.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700">Periode Dari</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700">Periode Sampai</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @if ($branchOptions->count() > 1)
                    <div>
                        <label for="branch_id" class="block text-sm font-medium text-gray-700">Cabang</label>
                        <select id="branch_id" name="branch_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            @if ($scope['cross_branch'] ?? false)
                                <option value="" @selected($selectedBranchId === null)>Semua Cabang</option>
                            @endif
                            @foreach ($branchOptions as $branch)
                                <option value="{{ $branch->id }}" @selected($selectedBranchId === $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                @endif
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua</option>
                        @foreach (InventoryBatchDisposalRequestStatus::values() as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ InventoryBatchDisposalRequestStatus::label($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="request_type" class="block text-sm font-medium text-gray-700">Jenis Request</label>
                    <select id="request_type" name="request_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua</option>
                        @foreach (InventoryBatchDisposalRequestType::values() as $type)
                            <option value="{{ $type }}" @selected(($filters['request_type'] ?? '') === $type)>{{ InventoryBatchDisposalRequestType::label($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="product" class="block text-sm font-medium text-gray-700">Produk</label>
                    <input id="product" name="product" type="search" value="{{ $filters['product'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" placeholder="Nama atau kode">
                </div>
                <div>
                    <label for="batch" class="block text-sm font-medium text-gray-700">Nomor Batch</label>
                    <input id="batch" name="batch" type="search" value="{{ $filters['batch'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="location_id" class="block text-sm font-medium text-gray-700">Lokasi</label>
                    <select id="location_id" name="location_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua</option>
                        @foreach ($filterOptions['locations'] as $location)
                            <option value="{{ $location->id }}" @selected((int) ($filters['location_id'] ?? 0) === $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="has_movement" class="block text-sm font-medium text-gray-700">Movement</label>
                    <select id="has_movement" name="has_movement" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        @foreach ($filterOptions['hasMovementOptions'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['has_movement'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="action_type" class="block text-sm font-medium text-gray-700">Action Log</label>
                    <select id="action_type" name="action_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua</option>
                        @foreach (InventoryBatchActionType::values() as $actionType)
                            <option value="{{ $actionType }}" @selected(($filters['action_type'] ?? '') === $actionType)>{{ InventoryBatchActionType::label($actionType) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">Terapkan Filter</button>
                    <a href="{{ route('inventory.reports.batch-disposals.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Reset</a>
                </div>
            </div>
        </form>

        <section aria-labelledby="disposal-summary-cards">
            <h3 id="disposal-summary-cards" class="mb-3 text-base font-semibold text-gray-900">Ringkasan</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-inventory.kpi-card label="Total Request" :value="format_number_id((int) $summary['total_requests'])" hint="Semua permintaan dalam filter" tone="primary" />
                <x-inventory.kpi-card label="Menunggu Approval" :value="format_number_id((int) $summary['pending_approval_count'])" hint="Status diajukan" tone="warning" />
                <x-inventory.kpi-card label="Disetujui" :value="format_number_id((int) $summary['approved_count'])" hint="Menunggu finalisasi" tone="neutral" />
                <x-inventory.kpi-card label="Ditolak" :value="format_number_id((int) $summary['rejected_count'])" hint="Permintaan ditolak" tone="danger" />
                <x-inventory.kpi-card label="Adjustment Dicatat" :value="format_number_id((int) $summary['adjustment_recorded_count'])" hint="Finalisasi selesai" tone="primary" />
                <x-inventory.kpi-card label="Qty Diajukan" :value="format_quantity_id((float) $summary['total_quantity_requested'])" hint="Total quantity request" tone="neutral" />
                <x-inventory.kpi-card label="Qty Adjustment Dicatat" :value="format_quantity_id((float) $summary['total_quantity_adjustment_recorded'])" hint="Hanya status adjustment dicatat" tone="neutral" />
                <x-inventory.kpi-card label="Movement Tertaut" :value="format_number_id((int) $summary['movement_linked_count'])" hint="Sudah punya ADJUSTMENT_OUT" tone="primary" />
            </div>
        </section>

        @if (($breakdowns['by_branch'] ?? collect())->isNotEmpty())
            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900">Per Cabang</h3>
                <ul class="mt-2 space-y-1 text-sm text-gray-600">
                    @foreach ($breakdowns['by_branch'] as $branchName => $count)
                        <li>{{ $branchName }}: {{ format_number_id((int) $count) }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                            @if ($scope['cross_branch'] ?? false)
                                <th scope="col" class="px-3 py-3 font-medium">Cabang</th>
                            @endif
                            <th scope="col" class="px-3 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">Batch</th>
                            <th scope="col" class="px-3 py-3 font-medium">Expired</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                            <th scope="col" class="px-3 py-3 font-medium">Jenis</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Qty</th>
                            <th scope="col" class="px-3 py-3 font-medium">Action Log</th>
                            <th scope="col" class="px-3 py-3 font-medium">Movement</th>
                            <th scope="col" class="px-3 py-3 font-medium">Dibuat oleh</th>
                            <th scope="col" class="px-3 py-3 font-medium">Approval/Finalisasi</th>
                            <th scope="col" class="px-3 py-3 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $item)
                            <tr class="align-top hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600">{{ format_datetime_id($item->submitted_at ?? $item->created_at) }}</td>
                                @if ($scope['cross_branch'] ?? false)
                                    <td class="px-3 py-3 text-gray-600">{{ $item->branch?->name ?? '—' }}</td>
                                @endif
                                <td class="px-3 py-3 text-gray-700">{{ $item->product?->name ?? '—' }}</td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $item->batch?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->batch?->expiry_date ? format_date_id($item->batch->expiry_date) : '—' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->location?->name ?? '—' }}</td>
                                <td class="px-3 py-3">@include('inventory.batch-disposal-requests._request-type-badge', ['requestType' => $item->request_type])</td>
                                <td class="px-3 py-3">@include('inventory.batch-disposal-requests._status-badge', ['status' => $item->status])</td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-900">{{ format_quantity_id((float) $item->quantity_requested) }}</td>
                                <td class="px-3 py-3 text-gray-600">
                                    @if ($item->actionLog)
                                        <span class="block font-medium text-gray-800">{{ $item->actionLog->actionTypeLabel() }}</span>
                                        <span class="text-xs">{{ $item->actionLog->actor?->name ?? '—' }} · {{ format_datetime_id($item->actionLog->acted_at) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600">
                                    @if ($item->movement)
                                        <span class="block font-medium text-gray-800">{{ $item->movement->movement_type }}</span>
                                        <span class="text-xs tabular-nums">OUT {{ format_quantity_id((float) $item->movement->quantity_out) }}</span>
                                        @if ($item->product)
                                            <a href="{{ route('inventory.products.stock-card', ['product' => $item->product, 'inventory_location_id' => $item->inventory_location_id, 'inventory_batch_id' => $item->inventory_batch_id]) }}" class="block text-xs text-brand-700 hover:text-brand-600">Kartu Stok</a>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->submittedBy?->name ?? '—' }}</td>
                                <td class="px-3 py-3 text-xs text-gray-600">
                                    @if ($item->approved_at)
                                        <span class="block">Disetujui: {{ $item->approvedBy?->name }}</span>
                                    @endif
                                    @if ($item->rejected_at)
                                        <span class="block">Ditolak: {{ $item->rejectedBy?->name }}</span>
                                    @endif
                                    @if ($item->finalized_at)
                                        <span class="block">Final: {{ $item->finalizedBy?->name }}</span>
                                    @endif
                                    @if (! $item->approved_at && ! $item->rejected_at && ! $item->finalized_at)
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <a href="{{ route('inventory.batch-disposal-requests.show', $item) }}" class="font-medium text-brand-700 hover:text-brand-600">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ ($scope['cross_branch'] ?? false) ? 14 : 13 }}" class="px-4 py-8 text-center text-sm text-gray-500">Tidak ada permintaan disposal/adjustment untuk filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500">
                Dibuat {{ format_datetime_id($generatedAt) }} · read-only audit report
            </div>

            @if ($rows->hasPages())
                <div class="border-t border-gray-100 px-4 py-3">{{ $rows->links() }}</div>
            @endif
        </section>
    </div>
</x-settings-shell>
