<x-settings-shell title="Piutang RME">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Piutang RME</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Monitoring tagihan RME aktif dengan status belum dibayar atau cicilan.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button variant="secondary" :href="route('rme.cashier.receivables.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="secondary" :href="route('rme.cashier.index')">Kembali ke Kasir RME</x-ui.button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Jumlah Invoice</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($summary['invoice_count'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Tagihan</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">Rp {{ number_format($summary['grand_total'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sudah Dibayar</p>
                <p class="mt-2 text-2xl font-bold text-emerald-700">Rp {{ number_format($summary['paid_total'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sisa Piutang</p>
                <p class="mt-2 text-2xl font-bold text-amber-700">Rp {{ number_format($summary['remaining_total'], 0, ',', '.') }}</p>
            </x-ui.card>
        </div>

        @php
            $bucketLabels = [
                '0-7' => '0–7 Hari',
                '8-14' => '8–14 Hari',
                '15-30' => '15–30 Hari',
                '>30' => '>30 Hari',
            ];
        @endphp
        <div class="grid gap-4 md:grid-cols-4">
            @foreach ($agingBuckets as $bucket)
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $bucketLabels[$bucket] }}</p>
                    <p class="mt-2 text-lg font-bold text-gray-900">{{ number_format($aging[$bucket]['count'], 0, ',', '.') }} invoice</p>
                    <p class="mt-1 text-sm font-semibold text-amber-700">Rp {{ number_format($aging[$bucket]['remaining'], 0, ',', '.') }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card>
            <form method="GET" action="{{ route('rme.cashier.receivables') }}" class="grid gap-4 md:grid-cols-6">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700">Cari</label>
                    <input id="search" name="search" type="text" value="{{ $filters['search'] }}"
                        placeholder="Pasien / invoice / kunjungan"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                </div>

                <div>
                    <label for="branch_id" class="block text-sm font-medium text-gray-700">Cabang</label>
                    <select id="branch_id" name="branch_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua Cabang RME</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === $branch->id)>
                                {{ $branch->code }} — {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">UNPAID + PARTIAL</option>
                        <option value="PARTIAL" @selected($filters['status'] === 'PARTIAL')>Cicilan / Sebagian</option>
                        <option value="UNPAID" @selected($filters['status'] === 'UNPAID')>Belum Dibayar</option>
                    </select>
                </div>

                <div>
                    <label for="aging_bucket" class="block text-sm font-medium text-gray-700">Aging</label>
                    <select id="aging_bucket" name="aging_bucket" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua Aging</option>
                        <option value="0-7" @selected(($filters['aging_bucket'] ?? null) === '0-7')>0–7 Hari</option>
                        <option value="8-14" @selected(($filters['aging_bucket'] ?? null) === '8-14')>8–14 Hari</option>
                        <option value="15-30" @selected(($filters['aging_bucket'] ?? null) === '15-30')>15–30 Hari</option>
                        <option value=">30" @selected(($filters['aging_bucket'] ?? null) === '>30')>&gt;30 Hari</option>
                    </select>
                </div>

                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700">Dari Tanggal</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                </div>

                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700">Sampai Tanggal</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                </div>

                <div class="flex items-end gap-2 md:col-span-6">
                    <x-ui.button type="submit" variant="primary">Terapkan Filter</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('rme.cashier.receivables')">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:title>Daftar Piutang Aktif</x-slot:title>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Pasien / Kunjungan</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Cabang</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Umur / Bucket</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Grand Total</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Sudah Dibayar</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Sisa Tagihan</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        @php
                            $paidAmount = (float) $invoice->payments->sum('amount');
                            $remainingAmount = max(0, round((float) $invoice->grand_total - $paidAmount, 2));
                            $statusLabel = match ($invoice->status) {
                                'PARTIAL' => 'Cicilan / Sebagian',
                                'UNPAID' => 'Belum Dibayar',
                                default => $invoice->status,
                            };
                            $ageDays = (int) optional($invoice->created_at)->copy()?->startOfDay()->diffInDays(\Carbon\Carbon::today());
                            $ageBucket = match (true) {
                                $ageDays <= 7 => '0-7',
                                $ageDays <= 14 => '8-14',
                                $ageDays <= 30 => '15-30',
                                default => '>30',
                            };
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-mono text-sm font-semibold text-gray-900">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-gray-500">{{ $invoice->created_at?->format('d/m/Y H:i') }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900">{{ $invoice->patient?->name ?? '-' }}</p>
                                <p class="text-xs text-gray-500">{{ $invoice->clinicVisit?->visit_number ?? '-' }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $invoice->branch?->code }} — {{ $invoice->branch?->name }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700">{{ $statusLabel }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-sm text-gray-700">{{ $ageDays }} hari</p>
                                <p class="text-xs text-gray-500">{{ $bucketLabels[$ageBucket] }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-900">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-emerald-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-bold text-amber-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    @if ($invoice->clinicVisit)
                                        <a href="{{ route('rme.cashier.show', [$invoice->clinicVisit, $invoice]) }}" class="text-sm font-medium text-teal-700 hover:text-teal-900">Detail</a>
                                        @if ($invoice->isPayable())
                                            <a href="{{ route('rme.cashier.payment.create', [$invoice->clinicVisit, $invoice]) }}" class="text-sm font-medium text-blue-700 hover:text-blue-900">
                                                {{ $invoice->isPartial() ? 'Bayar Cicilan' : 'Bayar' }}
                                            </a>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-400">Kunjungan tidak tersedia</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">
                                Belum ada piutang RME aktif sesuai filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            <div class="mt-4">
                {{ $invoices->links() }}
            </div>
        </x-ui.card>
    </div>
</x-settings-shell>
