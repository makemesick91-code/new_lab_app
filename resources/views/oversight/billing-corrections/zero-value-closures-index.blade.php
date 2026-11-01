@php
    use App\Modules\RmeInvoice\Support\RmeClinicalBillingConsistency;
    use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
@endphp
{{-- HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
     "Selesai Tanpa Biaya" closures, READ-ONLY. Invoice and visit references only: no
     patient name, record number or identity number. No form here posts anywhere. --}}
<x-settings-shell title="Tanpa Biaya (Rp0)">
    <x-ui.page-header title="Tanpa Biaya (Rp0)" subtitle="Tagihan Konsultasi dan Kontrol Rp0 yang diselesaikan tanpa pembayaran.">
        <x-slot:breadcrumb>Billing Correction · Tanpa Biaya (Rp0)</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.billing-corrections.zero-value-closures.index')">
        <div class="w-full md:w-52">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_from" label="Ditutup dari (WITA)" :value="$range->startDate" />
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_to" label="Sampai (WITA)" :value="$range->endDate" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="category" label="Kategori">
                <option value="">Semua</option>
                @foreach (RmeZeroValueCategory::ALL as $category)
                    <option value="{{ $category }}" @selected(($filters['category'] ?? null) === $category)>{{ RmeZeroValueCategory::label($category) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="treatment_id" label="Tindakan">
                <option value="">Semua tindakan</option>
                @foreach ($options['treatments'] as $o)
                    <option value="{{ $o['id'] }}" @selected((string) ($filters['treatment_id'] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="doctor_id" label="Dokter">
                <option value="">Semua dokter</option>
                @foreach ($options['doctors'] as $o)
                    <option value="{{ $o['id'] }}" @selected((string) ($filters['doctor_id'] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="closer_id" label="Kasir penutup">
                <option value="">Semua</option>
                @foreach ($options['cashiers'] as $o)
                    <option value="{{ $o['id'] }}" @selected((string) ($filters['closer_id'] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="creator_id" label="Pembuat tagihan">
                <option value="">Semua</option>
                @foreach ($options['cashiers'] as $o)
                    <option value="{{ $o['id'] }}" @selected((string) ($filters['creator_id'] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        {{-- Rule 218 — the clinical vs billing cross-check recorded at closure. --}}
        <div class="w-full md:w-52">
            <x-ui.select name="consistency_status" label="Cek klinis">
                <option value="">Semua</option>
                @foreach ([...RmeClinicalBillingConsistency::RECORDED_STATUSES, RmeClinicalBillingConsistency::STATUS_NOT_EVALUATED] as $status)
                    <option value="{{ $status }}" @selected(($filters['consistency_status'] ?? null) === $status)>{{ RmeClinicalBillingConsistency::statusLabel($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-56">
            <x-ui.select name="consistency_signal" label="Sinyal">
                <option value="">Semua</option>
                @foreach (RmeClinicalBillingConsistency::SIGNALS as $signal)
                    <option value="{{ $signal }}" @selected(($filters['consistency_signal'] ?? null) === $signal)>{{ RmeClinicalBillingConsistency::signalLabel($signal) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-48">
            <x-ui.input name="invoice_number" label="No. invoice" :value="$filters['invoice_number'] ?? ''" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.input name="visit_number" label="No. kunjungan" :value="$filters['visit_number'] ?? ''" />
        </div>
    </x-ui.filter-bar>

    <x-ui.card data-oversight-zero-value-summary>
        <h2 class="text-sm font-semibold text-ink">Ringkasan {{ $range->startDate }} s.d. {{ $range->endDate }} (WITA)</h2>
        <p class="mt-1 text-xs text-ink-soft">Tanpa Biaya bukan Lunas: tidak ada pembayaran, tidak menambah pendapatan, dan bukan piutang.</p>
        <dl class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div><dt class="text-xs text-ink-soft">Total Tanpa Biaya</dt><dd class="text-lg font-semibold text-ink" data-zv-total>{{ $summary['total'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Konsultasi Rp0</dt><dd class="text-lg font-semibold text-ink" data-zv-consultations>{{ $summary['consultations'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Kontrol Rp0</dt><dd class="text-lg font-semibold text-ink" data-zv-controls>{{ $summary['controls'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Dibuat &amp; ditutup akun yang sama</dt><dd class="text-lg font-semibold text-ink" data-zv-self-issued>{{ $summary['anomalies']['self_issued'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Anomali: ada baris pembayaran</dt><dd class="text-lg font-semibold {{ $summary['anomalies']['with_payment_rows'] > 0 ? 'text-danger-700' : 'text-ink' }}" data-zv-payment-anomaly>{{ $summary['anomalies']['with_payment_rows'] }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-ink-soft">"Dibuat &amp; ditutup akun yang sama" bersifat informasi — diizinkan keputusan Owner S6-D3 (tanpa persetujuan kedua). Anomali baris pembayaran seharusnya selalu 0.</p>

        <h3 class="mt-5 text-sm font-semibold text-ink">Cek klinis vs tagihan (dicatat saat penutupan)</h3>
        <dl class="mt-2 grid grid-cols-2 gap-3 md:grid-cols-5" data-zv-cbc-summary>
            <div><dt class="text-xs text-ink-soft">Perlu ditinjau</dt><dd class="text-lg font-semibold {{ $summary['consistency']['review_required'] > 0 ? 'text-warning-700' : 'text-ink' }}" data-zv-cbc-review>{{ $summary['consistency']['review_required'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Tanpa sinyal</dt><dd class="text-lg font-semibold text-ink" data-zv-cbc-insufficient>{{ $summary['consistency']['insufficient'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Belum dievaluasi</dt><dd class="text-lg font-semibold text-ink" data-zv-cbc-not-evaluated>{{ $summary['consistency']['not_evaluated'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Tindakan Awal berbayar tidak ditagih</dt><dd class="text-lg font-semibold text-ink" data-zv-cbc-signal-registered>{{ $summary['consistency']['signal_registered'] }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Odontogram: tindakan baru</dt><dd class="text-lg font-semibold text-ink" data-zv-cbc-signal-odontogram>{{ $summary['consistency']['signal_odontogram'] }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-ink-soft">
            Pemeriksaan ini bersifat <strong>detektif</strong> (keputusan Owner CB-Q2): sinyal tidak pernah menolak penutupan.
            "Tanpa sinyal" <strong>bukan</strong> berarti tagihan sesuai rekam medis &mdash; RME tulisan tangan tidak dapat diperiksa mesin,
            dan catatan tindakan dokter yang terstruktur baru akan dibangun pada sprint terpisah (CB-Q1/CB-Q3).
        </p>
    </x-ui.card>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([
            ['key' => 'by_branch', 'title' => 'Per cabang', 'col' => 'Cabang', 'data' => 'zv-by-branch'],
            ['key' => 'by_closer', 'title' => 'Per kasir penutup', 'col' => 'Kasir', 'data' => 'zv-by-closer'],
            ['key' => 'by_doctor', 'title' => 'Per dokter', 'col' => 'Dokter', 'data' => 'zv-by-doctor'],
        ] as $block)
            <div data-zv-block="{{ $block['data'] }}"><x-ui.card padding="">
                <h3 class="px-4 pt-4 text-sm font-semibold text-ink">{{ $block['title'] }}</h3>
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-ink-soft">{{ $block['col'] }}</th>
                            <th class="px-4 py-2 text-right font-medium text-ink-soft">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($summary[$block['key']] as $r)
                            <tr>
                                <td class="px-4 py-2 text-ink">{{ $r['name'] }}</td>
                                <td class="px-4 py-2 text-right text-ink">
                                    {{ $r['count'] }}
                                    @if (($r['self_issued'] ?? 0) > 0)
                                        <span class="text-xs text-ink-soft">({{ $r['self_issued'] }} dibuat sendiri)</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-2 text-ink-soft">Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </x-ui.card></div>
        @endforeach

        <div data-zv-block="zv-by-treatment"><x-ui.card padding="">
            <h3 class="px-4 pt-4 text-sm font-semibold text-ink">Per tindakan</h3>
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Tindakan</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Item</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Tagihan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($summary['by_treatment'] as $r)
                        <tr>
                            <td class="px-4 py-2 text-ink">{{ $r['name'] }}</td>
                            <td class="px-4 py-2 text-right text-ink">{{ $r['items'] }}</td>
                            <td class="px-4 py-2 text-right text-ink">{{ $r['invoices'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-2 text-ink-soft">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card></div>
    </div>

    <x-ui.card padding="" data-oversight-zero-value-list>
        <x-ui.table>
            <thead class="bg-navy-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Ditutup (WITA)</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Kunjungan</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Kategori</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Tindakan</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Dokter</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Pembuat tagihan</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Kasir penutup</th>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Cek klinis</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($rows as $row)
                    <tr data-zv-row="{{ $row->id }}">
                        <td class="px-4 py-3 text-ink">{{ \Illuminate\Support\Carbon::parse($row->zero_value_closed_at, 'UTC')->timezone(config('clinical.timezone', 'Asia/Makassar'))->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-ink">{{ $row->branch_name }}</td>
                        <td class="px-4 py-3 font-mono text-ink">{{ $row->invoice_number }}</td>
                        <td class="px-4 py-3 font-mono text-ink">{{ $row->visit_number }}</td>
                        <td class="px-4 py-3 text-ink">{{ RmeZeroValueCategory::label($row->zero_value_category) }}</td>
                        <td class="px-4 py-3 text-ink">
                            @foreach ($row->treatments as $t)
                                <span class="block">{{ $t['name'] }}@if ($t['count'] > 1) &times;{{ $t['count'] }}@endif</span>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-ink">{{ $row->doctor_name ?? '-' }}</td>
                        <td class="px-4 py-3 text-ink">{{ $row->creator_name ?? '-' }}</td>
                        <td class="px-4 py-3 text-ink">
                            {{ $row->closer_name ?? '-' }}
                            @if ($row->creator_is_closer)
                                <span class="block text-xs text-ink-soft" data-zv-self-issued-row>akun yang sama</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink" data-zv-cbc-status="{{ $row->consistency['status'] }}">
                            <span class="{{ $row->consistency['status'] === RmeClinicalBillingConsistency::STATUS_REVIEW_REQUIRED ? 'font-semibold text-warning-700' : 'text-ink-soft' }}">{{ RmeClinicalBillingConsistency::statusLabel($row->consistency['status']) }}</span>
                            @foreach ($row->consistency['signals'] as $signal)
                                <span class="block text-xs text-ink-soft" data-zv-cbc-signal="{{ $signal }}">{{ RmeClinicalBillingConsistency::signalLabel($signal) }}</span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-ink-soft">Belum ada tagihan Tanpa Biaya pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <div class="px-4 py-3">{{ $rows->links() }}</div>
    </x-ui.card>
</x-settings-shell>
