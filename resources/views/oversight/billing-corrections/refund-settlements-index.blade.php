@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
@endphp
{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-6, RF-8a) — refund payouts, read-only.
     Gross incoming, refund outflow and net received are three separate figures for
     the chosen clinical (WITA) period, attributed to the invoice branch. Liabilities
     (open obligations, approved-unpaid, in flight) are point-in-time and are never
     added to or deducted from a money total. No form here posts anywhere. --}}
<x-settings-shell title="Pembayaran Refund">
    <x-ui.page-header title="Pembayaran Refund" subtitle="Uang refund yang benar-benar keluar, dipisahkan dari pembayaran masuk.">
        <x-slot:breadcrumb>Billing Correction · Pembayaran Refund</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.billing-corrections.refund-settlements.index')">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="date_from" label="Dari (WITA)" :value="$range->startDate" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="date_to" label="Sampai (WITA)" :value="$range->endDate" />
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach (RmeRefundSettlementStatus::ALL as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ RmeRefundSettlementStatus::label($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-60">
            <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="No. pembayaran, permintaan, invoice" />
        </div>
    </x-ui.filter-bar>

    <x-ui.card padding="" data-oversight-refund-settlement-summary>
        <div class="px-4 pt-4">
            <h2 class="text-sm font-semibold text-ink">Ringkasan periode {{ $range->startDate }} s.d. {{ $range->endDate }} (WITA)</h2>
            <p class="mt-1 text-xs text-ink-soft">Neto = pembayaran masuk − refund dibayarkan pada periode ini. Atribusi: cabang tagihan. Kewajiban terbuka, refund disetujui-belum-dibayar, dan pembayaran dalam proses adalah posisi saat ini, bukan uang yang bergerak. Ketiganya saling tumpang tindih (dalam proses ⊆ disetujui-belum-dibayar ⊆ kewajiban terbuka) dan tidak boleh dijumlahkan.</p>
        </div>
        <x-ui.table>
            <thead class="bg-navy-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Pembayaran masuk (bruto)</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Refund dibayarkan</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Neto diterima</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Kewajiban terbuka</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Disetujui, belum dibayar</th>
                    <th class="px-4 py-3 text-right font-medium text-ink-soft">Dalam proses</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($summary as $row)
                    <tr data-oversight-settlement-branch="{{ $row['branch_id'] }}">
                        <td class="px-4 py-3">{{ $row['branch_name'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" data-gross>{{ OversightFormat::rupiahCents($row['gross_incoming_cents']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" data-outflow>{{ OversightFormat::rupiahCents($row['refund_outflow_cents']) }} <span class="text-xs text-ink-soft">({{ $row['refund_outflow_count'] }})</span></td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums" data-net>{{ OversightFormat::rupiahCents($row['net_received_cents']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiahCents($row['open_obligation_cents']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiahCents($row['approved_unsettled_cents']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiahCents($row['in_flight_cents']) }} <span class="text-xs text-ink-soft">({{ $row['in_flight_count'] }})</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-3 text-sm text-ink-soft">Tidak ada cabang dalam cakupan.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Belum ada pembayaran refund" description="Tidak ada pembayaran refund pada cakupan cabang ini." />
    @else
        <x-ui.card padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Pembayaran</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Dicatat dibayarkan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Metode</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rows as $s)
                        <tr data-oversight-settlement-row="{{ $s->id }}">
                            <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.refund-settlements.show', $s->id) }}">{{ $s->settlement_number }}</a></td>
                            <td class="px-4 py-3 text-ink-soft">{{ $s->settled_at ? OversightFormat::clinicalTime($s->settled_at, 'd/m/Y H:i').' WITA' : '—' }}</td>
                            <td class="px-4 py-3">{{ $s->branch?->name }}</td>
                            <td class="px-4 py-3">{{ RmeRefundSettlementMethod::label($s->method) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($s->amount) }}</td>
                            <td class="px-4 py-3">{{ RmeRefundSettlementStatus::label($s->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
        <div class="mt-3">{{ $rows->links() }}</div>
    @endif
</x-settings-shell>
