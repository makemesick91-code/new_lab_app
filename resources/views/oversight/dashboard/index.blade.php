@php
    use App\Modules\ClinicOversight\Support\ClinicalDateRange;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $t = $payload['totals'];
    $metricsUrl = route('oversight.dashboard.metrics', $carriedFilters);
    $branchId = $payload['selected_branch_id'];
    $periodLabel = $payload['is_single_day']
        ? OversightFormat::clinicalDate($payload['start_date'])
        : OversightFormat::clinicalDate($payload['start_date']).' – '.OversightFormat::clinicalDate($payload['end_date']);
@endphp
<x-settings-shell title="Dashboard Finance & Data Analyst">
    <x-ui.page-header title="Dashboard Finance & Data Analyst" subtitle="Ringkasan lintas cabang RME untuk periode tanggal klinik WITA (Asia/Makassar).">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Dashboard</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.dashboard.export', $carriedFilters)">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.dashboard.print', $carriedFilters)" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    @if ($errors->any())
        <x-ui.alert variant="danger">
            Filter tidak valid — data di bawah menampilkan periode {{ $periodLabel }}.
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    {{-- Rule 187: two inclusive WITA dates. The pickers keep end >= start in
         the browser; the server re-validates everything (order, format, span,
         bounds) and never exchanges the dates. --}}
    <x-ui.filter-bar :action="route('oversight.dashboard')">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($branchId ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        {{-- When the default (today) view rolls over at WITA midnight the feed
             announces the new range, so Terapkan never silently pins yesterday.
             An explicit range never moves, so typing is never overwritten. --}}
        <div class="contents" x-data="{ start: @js($payload['start_date']), end: @js($payload['end_date']) }"
            x-on:oversight-range-moved.document="start = $event.detail.start_date; end = $event.detail.end_date">
            <div class="w-full md:w-44">
                <x-ui.input type="date" name="start_date" label="Tanggal Mulai" :value="$payload['start_date']" required
                    min="{{ ClinicalDateRange::MIN_DATE }}" max="{{ ClinicalDateRange::MAX_DATE }}"
                    x-model="start" x-on:change="if (end < start) { end = start }" />
            </div>
            <div class="w-full md:w-44">
                <x-ui.input type="date" name="end_date" label="Tanggal Akhir" :value="$payload['end_date']" required
                    max="{{ ClinicalDateRange::MAX_DATE }}" x-bind:min="start" x-model="end" />
            </div>
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Terapkan Filter</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="$resetUrl">Reset</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="-mt-2 mb-4 flex flex-wrap items-center gap-2 text-xs text-ink-soft" data-range-presets>
        <span>Pilih cepat:</span>
        @foreach ($presets as $preset)
            @php
                $active = $preset['start_date'] === $payload['start_date'] && $preset['end_date'] === $payload['end_date'];
            @endphp
            <a href="{{ route('oversight.dashboard', array_filter(['branch_id' => $branchId, 'start_date' => $preset['start_date'], 'end_date' => $preset['end_date']])) }}"
                @class([
                    'rounded-full border px-3 py-1 focus:outline-none focus:ring-2 focus:ring-brand-500',
                    'border-brand-500 bg-brand-50 font-semibold text-brand-700' => $active,
                    'border-hairline bg-surface text-navy hover:bg-canvas' => ! $active,
                ])
                @if ($active) aria-current="true" @endif>{{ $preset['label'] }}</a>
        @endforeach
        <span class="text-ink-muted">· tanggal klinik WITA, maksimal {{ ClinicalDateRange::MAX_DAYS }} hari</span>
    </div>

    @if ($payload['branch_filter_rejected'])
        <x-ui.alert variant="warning">Cabang yang dipilih tidak termasuk cabang RME yang dapat Anda lihat. Tidak ada data ditampilkan.</x-ui.alert>
    @endif

    <div
        x-data="oversightDashboard(@js($payload), @js($metricsUrl))"
        x-init="start()"
        class="space-y-6"
        data-oversight-dashboard
        data-metrics-url="{{ $metricsUrl }}"
    >
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-soft">
            <span>
                Periode: <strong class="text-navy" x-text="period()">{{ $periodLabel }}</strong>
                <template x-if="data.is_today"><span> (hari ini)</span></template>
                <template x-if="!data.is_single_day"><span x-text="' (' + fmt(data.day_count) + ' hari)'"> ({{ $payload['day_count'] }} hari)</span></template>
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
                <span class="mt-1 block text-xs font-normal text-ink-soft">Pasien unik yang check-in pada periode</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Transaksi" data-kpi="transactions">
                <span x-text="fmt(data.totals.transactions)">{{ number_format($t['transactions'], 0, ',', '.') }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Batch pembayaran kasir pada periode</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Omzet" :accent="true" data-kpi="revenue">
                <span x-text="rp(data.totals.revenue_cents)">{{ OversightFormat::rupiahCents($t['revenue_cents']) }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Pembayaran RME diterima pada periode</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Piutang Aktif Per Saat Ini" data-kpi="receivables">
                <span x-text="rp(data.totals.receivable_cents)">{{ OversightFormat::rupiahCents($t['receivable_cents']) }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft"><span x-text="fmt(data.totals.receivable_invoices)">{{ $t['receivable_invoices'] }}</span> invoice belum lunas · saldo saat ini, <strong>tidak mengikuti periode tanggal</strong> (bukan piutang historis periode terpilih)</span>
            </x-ui.kpi-card>
            <x-ui.kpi-card label="Jumlah Pasien Cancel" data-kpi="cancellations">
                <span x-text="fmt(data.totals.cancellations)">{{ number_format($t['cancellations'], 0, ',', '.') }}</span>
                <span class="mt-1 block text-xs font-normal text-ink-soft">Kunjungan dibatalkan pada periode</span>
            </x-ui.kpi-card>
        </div>

        <x-ui.card title="Per Cabang" description="Nilai setiap cabang untuk periode tanggal klinik terpilih. Total pasien dan transaksi dihitung unik lintas cabang (dan lintas tanggal), sehingga bisa lebih kecil dari jumlah baris. Piutang Aktif adalah saldo saat ini.">
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-2">Cabang</th>
                        <th class="px-4 py-2 text-right">Pasien</th>
                        <th class="px-4 py-2 text-right">Transaksi</th>
                        <th class="px-4 py-2 text-right">Omzet</th>
                        <th class="px-4 py-2 text-right">Piutang Aktif (saat ini)</th>
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
                <li><strong>Periode</strong> — tanggal mulai dan tanggal akhir sama-sama termasuk (inklusif), dalam tanggal klinik WITA. Satu hari = tanggal mulai sama dengan tanggal akhir.</li>
                <li><strong>Jumlah Pasien</strong> — pasien unik dengan kunjungan bertanggal dalam periode yang sudah check-in, tidak dibatalkan. Pasien yang datang beberapa kali dalam periode dihitung satu. Pendaftar yang belum check-in tidak dihitung.</li>
                <li><strong>Jumlah Transaksi</strong> — satu operasi kasir (batch pembayaran) dalam periode. Pembayaran tunggal tanpa batch dihitung satu per pembayaran. Batch yang melunasi invoice di dua cabang dihitung di masing-masing cabang, tetapi sekali pada total.</li>
                <li><strong>Jumlah Omzet</strong> — jumlah pembayaran RME aktual dengan tanggal bayar dalam periode (WITA), diatribusikan ke cabang invoice. Tagihan lab tidak termasuk; nilai invoice bukan omzet.</li>
                <li><strong>Piutang Aktif Per Saat Ini</strong> — sisa tagihan invoice UNPAID/PARTIAL saat ini (grand total dikurangi pembayaran sah). <strong>Tidak mengikuti periode tanggal</strong>: angka ini sama untuk periode apa pun dan bukan piutang historis periode terpilih.</li>
                <li><strong>Jumlah Pasien Cancel</strong> — jumlah kunjungan berstatus dibatalkan yang waktu pembatalannya jatuh dalam periode. Bukan no-show.</li>
            </ul>
        </x-ui.card>
    </div>

    @push('scripts')
        <script>
            function oversightDashboard(initial, url) {
                const number = new Intl.NumberFormat('id-ID');
                const dmy = (iso) => String(iso || '').split('-').reverse().join('/');
                return {
                    data: initial,
                    paused: false,
                    error: false,
                    timer: null,
                    controller: null,
                    requestSeq: 0,
                    fmt(v) { return number.format(Number(v || 0)); },
                    // Rule 195 §25 — cents are kept, never truncated (mirrors OversightFormat::rupiahCents).
                    rp(cents) { const c = Math.trunc(Number(cents || 0)), a = Math.abs(c), f = a % 100; return 'Rp ' + (c < 0 ? '-' : '') + number.format(Math.trunc(a / 100)) + (f ? ',' + String(f).padStart(2, '0') : ''); },
                    announceRange() {
                        if (typeof CustomEvent !== 'function' || typeof document.dispatchEvent !== 'function') { return; }
                        document.dispatchEvent(new CustomEvent('oversight-range-moved', {
                            detail: { start_date: this.data.start_date, end_date: this.data.end_date },
                        }));
                    },
                    period() {
                        return this.data.is_single_day
                            ? dmy(this.data.start_date)
                            : dmy(this.data.start_date) + ' – ' + dmy(this.data.end_date);
                    },
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
                        // Only the newest request may write: an older response
                        // that resolves late is discarded, never shown over a
                        // newer one.
                        const seq = ++this.requestSeq;
                        try {
                            const res = await fetch(url, {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                                signal: this.controller.signal,
                            });
                            if (!res.ok) { throw new Error('HTTP ' + res.status); }
                            const body = await res.json();
                            if (seq !== this.requestSeq) { return; }
                            const moved = body.start_date !== this.data.start_date || body.end_date !== this.data.end_date;
                            this.data = body;
                            this.error = false;
                            if (moved) { this.announceRange(); }
                        } catch (e) {
                            if (seq === this.requestSeq && e.name !== 'AbortError') { this.error = true; }
                        } finally {
                            if (seq === this.requestSeq) { this.schedule(); }
                        }
                    },
                };
            }
        </script>
    @endpush
</x-settings-shell>
