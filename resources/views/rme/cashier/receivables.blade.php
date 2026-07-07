<x-settings-shell title="Piutang RME">
    <div class="space-y-6">
        <x-ui.page-header
            title="Piutang RME"
            subtitle="Monitoring tagihan RME aktif dengan status belum dibayar atau cicilan. Tagihan lunas (sisa tagihan Rp 0) tidak ditampilkan sebagai piutang aktif.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.receivables.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="secondary" :href="route('rme.cashier.index')">Kembali ke Kasir RME</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="warning">
            Follow-up WhatsApp dilakukan manual oleh kasir menggunakan Nomor WA pasien. Sistem tidak mengirim pesan WhatsApp otomatis dan tidak melakukan otomasi follow-up.
        </x-ui.alert>

        <div class="grid gap-4 md:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Jumlah Invoice</p>
                <p class="mt-2 text-2xl font-bold text-navy">{{ number_format($summary['invoice_count'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Total Tagihan</p>
                <p class="mt-2 text-2xl font-bold text-navy">Rp {{ number_format($summary['grand_total'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Sudah Dibayar</p>
                <p class="mt-2 text-2xl font-bold text-success-700">Rp {{ number_format($summary['paid_total'], 0, ',', '.') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Sisa Piutang</p>
                <p class="mt-2 text-2xl font-bold text-warning-700">Rp {{ number_format($summary['remaining_total'], 0, ',', '.') }}</p>
            </x-ui.card>
        </div>

        @php
            $bucketLabels = [
                '0-7' => '0–7 Hari',
                '8-14' => '8–14 Hari',
                '15-30' => '15–30 Hari',
                '>30' => '>30 Hari',
            ];
            $followUpStatusLabels = [
                'NEW' => 'Baru',
                'CONTACTED' => 'Sudah Dihubungi',
                'PROMISED' => 'Janji Bayar',
                'FOLLOW_UP_LATER' => 'Follow-up Lagi',
                'ESCALATED' => 'Eskalasi',
                'CLOSED' => 'Selesai',
            ];
        @endphp
        <div class="grid gap-4 md:grid-cols-4">
            @foreach ($agingBuckets as $bucket)
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $bucketLabels[$bucket] }}</p>
                    <p class="mt-2 text-lg font-bold text-navy">{{ number_format($aging[$bucket]['count'], 0, ',', '.') }} invoice</p>
                    <p class="mt-1 text-sm font-semibold text-warning-700">Rp {{ number_format($aging[$bucket]['remaining'], 0, ',', '.') }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.filter-bar :action="route('rme.cashier.receivables')" method="GET">
            <div class="w-full md:flex-1 md:min-w-[12rem]">
                <x-ui.input label="Cari" id="search" name="search" :value="$filters['search']"
                    placeholder="Pasien / invoice / kunjungan" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Cabang" id="branch_id" name="branch_id">
                    <option value="">Semua Cabang RME</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === $branch->id)>
                            {{ $branch->code }} — {{ $branch->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Status" id="status" name="status">
                    <option value="">UNPAID + PARTIAL</option>
                    <option value="PARTIAL" @selected($filters['status'] === 'PARTIAL')>Cicilan / Sebagian</option>
                    <option value="UNPAID" @selected($filters['status'] === 'UNPAID')>Belum Dibayar</option>
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Aging" id="aging_bucket" name="aging_bucket">
                    <option value="">Semua Aging</option>
                    <option value="0-7" @selected(($filters['aging_bucket'] ?? null) === '0-7')>0–7 Hari</option>
                    <option value="8-14" @selected(($filters['aging_bucket'] ?? null) === '8-14')>8–14 Hari</option>
                    <option value="15-30" @selected(($filters['aging_bucket'] ?? null) === '15-30')>15–30 Hari</option>
                    <option value=">30" @selected(($filters['aging_bucket'] ?? null) === '>30')>&gt;30 Hari</option>
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.input label="Dari Tanggal" id="date_from" name="date_from" type="date" :value="$filters['date_from']" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.input label="Sampai Tanggal" id="date_to" name="date_to" type="date" :value="$filters['date_to']" />
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan Filter</x-ui.button>
                <x-ui.button variant="secondary" :href="route('rme.cashier.receivables')">Reset</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card>
            <x-slot:title>Daftar Piutang Aktif</x-slot:title>

            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien / Kunjungan</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Cabang</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Follow-up Terakhir</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Reminder Berikutnya</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Umur / Bucket</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Grand Total</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Sudah Dibayar</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Sisa Tagihan</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
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

                            $followUp = $invoice->latestFollowUp;
                            $nextDate = $followUp?->next_follow_up_date;
                            [$dueLabel, $dueTone] = (function () use ($nextDate) {
                                if (! $nextDate) {
                                    return ['Belum Ada', 'bg-hairline text-ink-soft'];
                                }
                                $today = \Carbon\Carbon::today();
                                if ($nextDate->lt($today)) {
                                    return ['Jatuh Tempo', 'bg-danger-100 text-danger-700'];
                                }
                                if ($nextDate->isSameDay($today)) {
                                    return ['Hari Ini', 'bg-warning-100 text-warning-700'];
                                }
                                return ['Terjadwal', 'bg-info-100 text-brand-700'];
                            })();
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-mono text-sm font-semibold text-navy">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-ink-soft">{{ $invoice->created_at?->format('d/m/Y H:i') }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-navy">{{ $invoice->patient?->name ?? '-' }}</p>
                                <p class="text-xs text-ink-soft">{{ $invoice->clinicVisit?->visit_number ?? '-' }}</p>
                                <p class="text-xs text-ink-soft">WA: {{ $invoice->patient?->whatsapp_number ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-ink">{{ $invoice->branch?->code }} — {{ $invoice->branch?->name }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge tone="warning">{{ $statusLabel }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                @if ($followUp)
                                    <p class="text-sm font-medium text-navy">{{ $followUpStatusLabels[$followUp->status] ?? $followUp->status }}</p>
                                    <p class="text-xs text-ink-soft">{{ $followUp->created_at?->format('d/m/Y H:i') }}</p>
                                @else
                                    <span class="text-xs text-ink-muted">Belum ada follow-up</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $dueTone }}">{{ $dueLabel }}</span>
                                @if ($nextDate)
                                    <p class="mt-1 text-xs text-ink-soft">{{ $nextDate->format('d/m/Y') }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-sm text-ink">{{ $ageDays }} hari</p>
                                <p class="text-xs text-ink-soft">{{ $bucketLabels[$ageBucket] }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-navy">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-bold text-warning-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    @if ($invoice->clinicVisit)
                                        <a href="{{ route('rme.cashier.show', [$invoice->clinicVisit, $invoice]) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Detail</a>
                                        @if ($invoice->isPayable())
                                            <a href="{{ route('rme.cashier.payment.create', [$invoice->clinicVisit, $invoice]) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">
                                                {{ $invoice->isPartial() ? 'Bayar Cicilan' : 'Bayar' }}
                                            </a>
                                        @endif
                                    @else
                                        <span class="text-xs text-ink-muted">Kunjungan tidak tersedia</span>
                                    @endif
                                    @if ($invoice->isPayable())
                                        <a href="{{ route('rme.cashier.receivables.follow-ups.create', $invoice) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Tambah Follow-up</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-8 text-center text-sm text-ink-soft">
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
