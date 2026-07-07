@php
    use App\Modules\Inventory\Enums\InventoryBatchActionType;
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
@endphp

<x-settings-shell title="Closing Bulanan Governance Batch">
    <div class="space-y-6">
        <x-ui.page-header title="Closing Bulanan Governance Batch"
            subtitle="Paket review bulanan untuk expiry, action log, disposal, return supplier, dan adjustment ledger batch. Closing pack ini bersifat audit/read-only — tidak mengubah stok.">
            <x-slot:breadcrumb>Inventory · Laporan · Closing Bulanan</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('inventory.reports.batch-monthly-closing.export', $filters)">Export CSV</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('inventory.reports.batch-monthly-closing.print', $filters)" target="_blank" rel="noopener">Print Pack</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('inventory.reports.batch-disposals.index', $filters)">Laporan Disposal</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.reports.batch-monthly-closing.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="year" class="block text-sm font-medium text-gray-700">Tahun</label>
                    <input id="year" name="year" type="number" min="2020" value="{{ $filters['year'] ?? now()->year }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="month" class="block text-sm font-medium text-gray-700">Bulan</label>
                    <select id="month" name="month" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        @foreach ($filterOptions['months'] as $monthValue => $monthLabel)
                            <option value="{{ $monthValue }}" @selected((int) ($filters['month'] ?? now()->month) === $monthValue)>{{ $monthLabel }}</option>
                        @endforeach
                    </select>
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
                    @if ($scope['cross_branch'] ?? false)
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="include_all_branches" value="1" @checked(! empty($filters['include_all_branches']) || $selectedBranchId === null) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                Semua cabang diizinkan
                            </label>
                        </div>
                    @endif
                @else
                    <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                @endif
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
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">Terapkan</button>
                    <a href="{{ route('inventory.reports.batch-monthly-closing.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Reset</a>
                </div>
            </div>
        </form>

        <p class="text-sm text-gray-600">Periode closing: <span class="font-semibold">{{ $periodLabel }}</span> ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})</p>

        <section aria-labelledby="closing-summary-cards">
            <h3 id="closing-summary-cards" class="mb-3 text-base font-semibold text-gray-900">Ringkasan Bulanan</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-inventory.kpi-card label="Batch Kedaluwarsa Bersaldo" :value="format_number_id((int) $summary['total_expired_batches_with_positive_stock'])" hint="Ledger > 0" tone="danger" />
                <x-inventory.kpi-card label="Batch Akan Kedaluwarsa Bersaldo" :value="format_number_id((int) $summary['total_near_expiry_batches_with_positive_stock'])" hint="Dalam 90 hari" tone="warning" />
                <x-inventory.kpi-card label="Action Log Bulan Ini" :value="format_number_id((int) $summary['total_action_logs'])" hint="Operasional batch" tone="neutral" />
                <x-inventory.kpi-card label="Request Disposal/Return" :value="format_number_id((int) $summary['total_disposal_requests'])" hint="Workflow evidence" tone="primary" />
                <x-inventory.kpi-card label="Menunggu Approval" :value="format_number_id((int) $summary['pending_approval_requests'])" hint="Status diajukan" tone="warning" />
                <x-inventory.kpi-card label="Adjustment Dicatat" :value="format_number_id((int) $summary['adjustment_recorded_requests'])" hint="Finalisasi selesai" tone="primary" />
                <x-inventory.kpi-card label="Qty Diajukan" :value="format_quantity_id((float) $summary['total_quantity_requested'])" hint="Total quantity request" tone="neutral" />
                <x-inventory.kpi-card label="Qty Adjustment Dicatat" :value="format_quantity_id((float) $summary['total_quantity_adjustment_recorded'])" hint="Hanya adjustment dicatat" tone="neutral" />
                <x-inventory.kpi-card label="Movement Tertaut" :value="format_number_id((int) $summary['movement_linked_requests'])" hint="Sudah punya movement" tone="primary" />
                <x-inventory.kpi-card label="Anomali/Follow-up" :value="format_number_id((int) $summary['exception_count'])" hint="Perlu tindak lanjut" tone="danger" />
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">A. Ringkasan Risiko Expiry</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @if ($scope['cross_branch'] ?? false)<th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Cabang</th>@endif
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Produk</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Batch</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Expiry</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Status</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Lokasi</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700 tabular-nums">Qty Ledger</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Latest Action</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($expiryRiskRows as $row)
                            <tr class="{{ $row['expiry_status'] === 'expired' ? 'bg-rose-50/40' : 'bg-amber-50/40' }}">
                                @if ($scope['cross_branch'] ?? false)<td class="px-3 py-2">{{ $row['branch']?->name ?? '—' }}</td>@endif
                                <td class="px-3 py-2">{{ $row['product']?->name ?? '—' }}</td>
                                <td class="px-3 py-2 font-medium">{{ $row['batch']?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['expiry_date'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['expiry_label'] }}</td>
                                <td class="px-3 py-2">{{ $row['location_name'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ format_quantity_id($row['ledger_qty']) }}</td>
                                <td class="px-3 py-2">{{ $row['latest_action']?->actionTypeLabel() ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['batch'])
                                        <a href="{{ route('inventory.batches.show', $row['batch']) }}" class="text-brand-700 hover:underline">Batch</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 9 : 8 }}" class="px-3 py-6 text-center text-gray-500">Tidak ada batch expiry/near-expiry bersaldo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">B. Ringkasan Action Log</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Tanggal</th>
                            @if ($scope['cross_branch'] ?? false)<th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Cabang</th>@endif
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Produk</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Batch</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Action Type</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Note</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Actor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($actionLogRows as $log)
                            <tr>
                                <td class="px-3 py-2">{{ format_datetime_id($log->acted_at) }}</td>
                                @if ($scope['cross_branch'] ?? false)<td class="px-3 py-2">{{ $log->branch?->name ?? '—' }}</td>@endif
                                <td class="px-3 py-2">{{ $log->batch?->product?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $log->batch?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $log->actionTypeLabel() }}</td>
                                <td class="px-3 py-2">{{ $log->note ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $log->actor?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 7 : 6 }}" class="px-3 py-6 text-center text-gray-500">Tidak ada action log pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">C. Ringkasan Disposal / Return / Adjustment</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Tanggal</th>
                            @if ($scope['cross_branch'] ?? false)<th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Cabang</th>@endif
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Produk</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Batch</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Lokasi</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Jenis</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Status</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700 tabular-nums">Qty</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Evidence</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Movement</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($disposalRows as $request)
                            <tr>
                                <td class="px-3 py-2">{{ format_datetime_id($request->submitted_at ?? $request->created_at) }}</td>
                                @if ($scope['cross_branch'] ?? false)<td class="px-3 py-2">{{ $request->branch?->name ?? '—' }}</td>@endif
                                <td class="px-3 py-2">{{ $request->product?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $request->batch?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $request->location?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $request->requestTypeLabel() }}</td>
                                <td class="px-3 py-2">{{ $request->statusLabel() }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ format_quantity_id((float) $request->quantity_requested) }}</td>
                                <td class="px-3 py-2">{{ $request->evidence_reference ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $request->movement?->movement_type ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('inventory.batch-disposal-requests.show', $request) }}" class="text-brand-700 hover:underline">Request</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 11 : 10 }}" class="px-3 py-6 text-center text-gray-500">Tidak ada permintaan disposal/return pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">D. Ledger Evidence (ADJUSTMENT_OUT)</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Tanggal Movement</th>
                            @if ($scope['cross_branch'] ?? false)<th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Cabang</th>@endif
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Produk</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Batch</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Lokasi</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Movement Type</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700 tabular-nums">Qty Out</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Reference</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Stock Card</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($ledgerEvidenceRows as $row)
                            @php $movement = $row['movement']; $request = $row['request']; @endphp
                            <tr>
                                <td class="px-3 py-2">{{ $movement->movement_date }}</td>
                                @if ($scope['cross_branch'] ?? false)<td class="px-3 py-2">{{ $row['branch']?->name ?? '—' }}</td>@endif
                                <td class="px-3 py-2">{{ $row['product']?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['batch']?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['location']?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $movement->movement_type }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ format_quantity_id((float) $movement->quantity_out) }}</td>
                                <td class="px-3 py-2">{{ $movement->reference_number ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['product'])
                                        <a href="{{ route('inventory.products.stock-card', $row['product']) }}" class="text-brand-700 hover:underline">Kartu Stok</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 9 : 8 }}" class="px-3 py-6 text-center text-gray-500">Tidak ada bukti ADJUSTMENT_OUT pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-rose-200 bg-rose-50/30 p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">E. Follow-up / Exceptions</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Jenis</th>
                            @if ($scope['cross_branch'] ?? false)<th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Cabang</th>@endif
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Produk</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Batch</th>
                            <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-700">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($exceptionRows as $exception)
                            <tr>
                                <td class="px-3 py-2 font-medium text-rose-800">{{ $exception['type'] }}</td>
                                @if ($scope['cross_branch'] ?? false)<td class="px-3 py-2">{{ $exception['branch']?->name ?? '—' }}</td>@endif
                                <td class="px-3 py-2">{{ $exception['product']?->name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $exception['batch']?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $exception['label'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 5 : 4 }}" class="px-3 py-6 text-center text-gray-500">Tidak ada anomali terdeteksi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">F. Checklist Closing</h3>
            <p class="mt-1 text-sm text-gray-500">Centang manual saat print atau arsip governance. Tidak mengunci workflow operasional.</p>
            <ul class="mt-3 space-y-2">
                @foreach ($checklist as $item)
                    <li class="flex items-start gap-2 text-sm text-gray-700">
                        <span class="mt-0.5 inline-block h-4 w-4 rounded border border-gray-400"></span>
                        <span>{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-settings-shell>
