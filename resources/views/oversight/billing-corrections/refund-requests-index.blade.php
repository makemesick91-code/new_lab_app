@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (RF-8/RF-8a) — refund requests,
     read-only. No form on this page posts anywhere, and nothing here is money paid. --}}
<x-settings-shell title="Permintaan Refund">
    <x-ui.page-header title="Permintaan Refund" subtitle="Permintaan pengembalian kelebihan bayar dan keputusannya. Belum ada uang yang dibayarkan oleh modul ini.">
        <x-slot:breadcrumb>Billing Correction · Permintaan Refund</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.card data-oversight-refund-approved-unsettled>
        <h2 class="text-sm font-semibold text-ink">Refund disetujui — belum dibayar</h2>
        <p class="mt-1 text-xs text-ink-soft">
            Angka terpisah: tidak dikurangkan dari omzet, tidak ditambahkan ke piutang, kas, atau bank.
            Atribusi: cabang tagihan asal.
        </p>
        @if ($approvedUnsettled === [])
            <p class="mt-3 text-sm text-ink-soft">Tidak ada permintaan yang disetujui dan belum dibayar.</p>
        @else
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($approvedUnsettled as $branchId => $summary)
                    <li data-approved-unsettled-branch="{{ $branchId }}">
                        {{ collect($branchOptions)->firstWhere('id', $branchId)['name'] ?? ('Cabang #'.$branchId) }}:
                        <strong class="tabular-nums">{{ OversightFormat::rupiahCents($summary['amount_cents']) }}</strong>
                        ({{ $summary['count'] }} permintaan)
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.filter-bar :action="route('oversight.billing-corrections.refund-requests.index')">
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
                @foreach (RmeRefundRequestStatus::ALL as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ RmeRefundRequestStatus::label($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-64">
            <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="No. permintaan refund" />
        </div>
    </x-ui.filter-bar>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Belum ada permintaan refund" description="Tidak ada permintaan refund pada cakupan cabang ini." />
    @else
        <x-ui.card padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Permintaan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Diajukan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rows as $r)
                        <tr data-oversight-refund-row="{{ $r->id }}">
                            <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.refund-requests.show', $r->id) }}">{{ $r->request_number }}</a></td>
                            <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($r->requested_at, 'd/m/Y H:i') }} WITA</td>
                            <td class="px-4 py-3">{{ $r->branch?->name }}</td>
                            <td class="px-4 py-3 font-mono">{{ $r->invoice?->invoice_number }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($r->amount) }}</td>
                            <td class="px-4 py-3">{{ RmeRefundRequestStatus::label($r->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
        <div class="mt-3">{{ $rows->links() }}</div>
    @endif
</x-settings-shell>
