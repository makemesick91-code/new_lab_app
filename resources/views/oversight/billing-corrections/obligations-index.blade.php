@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
@endphp
{{-- FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (PP-9) — overpayment obligations,
     read-only. No form on this page posts anywhere. --}}
<x-settings-shell title="Kewajiban Kelebihan Bayar">
    <x-ui.page-header title="Kewajiban Kelebihan Bayar" subtitle="Uang yang wajib dikembalikan klinik setelah koreksi tagihan menurunkan total di bawah pembayaran yang sudah diterima.">
        <x-slot:breadcrumb>Billing Correction · Kewajiban Kelebihan Bayar</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.alert variant="warning">
        Kewajiban berstatus <strong>{{ RmeOverpaymentObligation::LABELS[RmeOverpaymentObligation::STATUS_OPEN] }}</strong>:
        uang belum dikembalikan dan modul pengembalian (refund) belum tersedia. Jangan mengembalikan uang di luar sistem.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('oversight.billing-corrections.obligations.index')">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach (RmeOverpaymentObligation::STATUSES as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ RmeOverpaymentObligation::LABELS[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-64">
            <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="No. kewajiban atau invoice" />
        </div>
    </x-ui.filter-bar>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Belum ada kewajiban" description="Tidak ada kewajiban kelebihan bayar pada cakupan cabang ini." />
    @else
        <x-ui.card padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Kewajiban</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Dicatat</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Koreksi Asal</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rows as $o)
                        <tr data-oversight-obligation-row="{{ $o->id }}">
                            <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.obligations.show', $o->id) }}">{{ $o->obligation_number }}</a></td>
                            <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($o->created_at, 'd/m/Y H:i') }} WITA</td>
                            <td class="px-4 py-3">{{ $o->branch?->name }}</td>
                            <td class="px-4 py-3 font-mono">{{ $o->invoice?->invoice_number }}</td>
                            <td class="px-4 py-3 font-mono">{{ $o->sourceCorrection?->correction_number }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($o->amount) }}</td>
                            <td class="px-4 py-3">{{ $o->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
        <div class="mt-3">{{ $rows->links() }}</div>
    @endif
</x-settings-shell>
