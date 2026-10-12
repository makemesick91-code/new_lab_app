@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $t = $payload['totals'];
@endphp
<x-settings-shell title="Dashboard Finance & Data Analyst">
    <x-ui.page-header title="Dashboard Finance & Data Analyst" subtitle="Ringkasan harian lintas cabang RME — tanggal klinik WITA (Asia/Makassar).">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Dashboard</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.dashboard.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.dashboard.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.dashboard')">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="date" label="Tanggal klinik" :value="$payload['date']" />
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.dashboard')">Hari ini</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($payload['branch_filter_rejected'])
        <x-ui.alert variant="warning">Cabang yang dipilih tidak termasuk cabang RME yang dapat Anda lihat. Tidak ada data ditampilkan.</x-ui.alert>
    @endif

    <div
        x-data="oversightDashboard(@js($payload), @js(route('oversight.dashboard.metrics', request()->query())))"
        x-init="start()"
        class="space-y-6"
        data-oversight-dashboard
    >
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-soft">
            <span>
                Tanggal: <strong class="text-navy" x-text="data.date">{{ $payload['date'] }}</strong>
                <template x-if="data.is_today"><span> (hari ini)</span></template>
                · Cabang: <strong class="text-navy">{{ $payload['selected_branch_id'] ? collect($branchOptions)->firstWhere('id', $payload['selected_branch_id'])['name'] ?? '-' : 'Semua cabang RME' }}</strong>
            </span>
            <span>
                Diperbarui: <strong class="text-navy" x-text="data.generated_at">{{ $payload['generated_at'] }}</strong> WITA
                · auto-refresh 60 detik <span x-show="paused" x-cloak>(dijeda — tab tidak aktif)</span>
                <span x-show="error" x-cloak class="text-danger-700">· gagal memuat ulang, menampilkan data terakhir</span>
            </span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.kpi-card label="Jumlah Pasien" data-kpi="patients">
                <span x-text="fmt(data.totals.patients)">{{ number_format($t['patients'], 0, ',', '.') }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Pasien unik yang check-in</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Transaksi" data-kpi="transactions">
                <span x-text="fmt(data.totals.transactions)">{{ number_format($t['transactions'], 0, ',', '.') }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Batch pembayaran kasir</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Omzet" :accent="true" data-kpi="revenue">
                <span x-text="rp(data.totals.revenue_cents)">{{ OversightFormat::rupiahCents($t['revenue_cents']) }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Pembayaran RME diterima</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Piutang Aktif Per Saat Ini" data-kpi="receivables">
                <span x-text="rp(data.totals.receivable_cents)">{{ OversightFormat::rupiahCents($t['receivable_cents']) }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft"><span x-text="fmt(data.totals.receivable_invoices)">{{ $t['receivable_invoices'] }}</span> invoice belum lunas · tidak dibatasi tanggal</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Pasien Cancel" data-kpi="cancellations">
                <span x-text="fmt(data.totals.cancellations)">{{ number_format($t['cancellations'], 0, ',', '.') }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Kunjungan dibatalkan pada tanggal ini</span>
            </x-ui.kpi-card>
        </div>

        <x-ui.card title="Per Cabang" description="Nilai setiap cabang untuk tanggal klinik terpilih. Total pasien dan transaksi dihitung unik lintas cabang, sehingga bisa lebih kecil dari jumlah baris.">
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-2">Cabang</th>
                        <th class="px-4 py-2 text-right">Pasien</th>
                        <th class="px-4 py-2 text-right">Transaksi</th>
                        <th class="px-4 py-2 text-right">Omzet</th>
                        <th class="px-4 py-2 text-right">Piutang Aktif</th>
                        <th class="px-4 py-2 text-right">Invoice Piutang</th>
                        <th class="px-4 py-2 text-right">Cancel</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    <template x-for="row in data.branches" :key="row.branch_id">
                        <tr>
                            <td class="px-4 py-2 text-navy"><span x-text="row.branch_name"></span> <span class="text-xs text-ink-soft" x-text="'(' + row.branch_code + ')'"></span></td>
                            <td class="px-4 py-2 text-right" x-text="fmt(row.patients)"></td>
                            <td class="px-4 py-2 text-right" x-text="fmt(row.transactions)"></td>
                            <td class="px-4 py-2 text-right" x-text="rp(row.revenue_cents)"></td>
                            <td class="px-4 py-2 text-right" x-text="rp(row.receivable_cents)"></td>
                            <td class="px-4 py-2 text-right" x-text="fmt(row.receivable_invoices)"></td>
                            <td class="px-4 py-2 text-right" x-text="fmt(row.cancellations)"></td>
                        </tr>
                    </template>
                    <tr x-show="data.branches.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-ink-soft">Tidak ada cabang dalam cakupan.</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-hairline bg-canvas font-semibold text-navy">
                    <tr>
                        <td class="px-4 py-2">Total (unik lintas cabang)</td>
                        <td class="px-4 py-2 text-right" x-text="fmt(data.totals.patients)"></td>
                        <td class="px-4 py-2 text-right" x-text="fmt(data.totals.transactions)"></td>
                        <td class="px-4 py-2 text-right" x-text="rp(data.totals.revenue_cents)"></td>
                        <td class="px-4 py-2 text-right" x-text="rp(data.totals.receivable_cents)"></td>
                        <td class="px-4 py-2 text-right" x-text="fmt(data.totals.receivable_invoices)"></td>
                        <td class="px-4 py-2 text-right" x-text="fmt(data.totals.cancellations)"></td>
                    </tr>
                </tfoot>
            </x-ui.table>

            {{-- Server-rendered table for print, no-JS and screen readers that
                 do not run the polling component. Same numbers as above. --}}
            <noscript>
                <table class="mt-4 min-w-full text-sm">
                    @foreach ($payload['branches'] as $row)
                        <tr><td>{{ $row['branch_name'] }}</td><td>{{ $row['patients'] }}</td><td>{{ $row['transactions'] }}</td><td>{{ OversightFormat::rupiahCents($row['revenue_cents']) }}</td><td>{{ OversightFormat::rupiahCents($row['receivable_cents']) }}</td><td>{{ $row['receivable_invoices'] }}</td><td>{{ $row['cancellations'] }}</td></tr>
                    @endforeach
                </table>
            </noscript>
        </x-ui.card>

        <x-ui.card title="Definisi Metrik" padding="p-5">
            <ul class="list-inside list-disc space-y-1 text-sm text-ink-soft">
                <li><strong>Jumlah Pasien</strong> — pasien unik dengan kunjungan bertanggal hari klinik terpilih yang sudah check-in, tidak dibatalkan. Pendaftar yang belum check-in tidak dihitung.</li>
                <li><strong>Jumlah Transaksi</strong> — satu operasi kasir (batch pembayaran). Pembayaran tunggal tanpa batch dihitung satu per pembayaran. Batch yang melunasi invoice di dua cabang dihitung di masing-masing cabang, tetapi sekali pada total.</li>
                <li><strong>Jumlah Omzet</strong> — jumlah pembayaran RME aktual dengan tanggal bayar pada hari klinik terpilih (WITA), diatribusikan ke cabang invoice. Tagihan lab tidak termasuk; nilai invoice bukan omzet.</li>
                <li><strong>Piutang Aktif Per Saat Ini</strong> — sisa tagihan invoice UNPAID/PARTIAL saat ini (grand total dikurangi pembayaran sah). Tidak difilter tanggal.</li>
                <li><strong>Jumlah Pasien Cancel</strong> — jumlah kunjungan berstatus dibatalkan yang waktu pembatalannya jatuh pada hari klinik terpilih. Bukan no-show.</li>
            </ul>
        </x-ui.card>
    </div>

    @push('scripts')
        <script>
            function oversightDashboard(initial, url) {
                const number = new Intl.NumberFormat('id-ID');
                return {
                    data: initial,
                    paused: false,
                    error: false,
                    timer: null,
                    controller: null,
                    fmt(v) { return number.format(Number(v || 0)); },
                    rp(cents) { return 'Rp ' + number.format(Math.trunc(Number(cents || 0) / 100)); },
                    start() {
                        this.schedule();
                        document.addEventListener('visibilitychange', () => {
                            if (document.hidden) {
                                this.paused = true;
                                clearTimeout(this.timer);
                            } else {
                                this.paused = false;
                                this.refresh();
                            }
                        });
                    },
                    schedule() {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.refresh(), 60000);
                    },
                    async refresh() {
                        if (document.hidden) { this.paused = true; return; }
                        if (this.controller) { this.controller.abort(); }
                        this.controller = new AbortController();
                        try {
                            const res = await fetch(url, {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                                signal: this.controller.signal,
                            });
                            if (!res.ok) { throw new Error('HTTP ' + res.status); }
                            this.data = await res.json();
                            this.error = false;
                        } catch (e) {
                            if (e.name !== 'AbortError') { this.error = true; }
                        } finally {
                            this.schedule();
                        }
                    },
                };
            }
        </script>
    @endpush
</x-settings-shell>
