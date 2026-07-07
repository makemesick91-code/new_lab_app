@php
    $methodLabels = [
        'CASH' => 'Tunai',
        'BANK_TRANSFER' => 'Transfer Bank',
        'QRIS' => 'QRIS',
        'CARD' => 'Kartu',
        'OTHER' => 'Lainnya',
    ];
@endphp

<x-settings-shell title="Laporan Pembayaran">
    <x-ui.page-header title="Laporan Pembayaran">
        <x-slot:breadcrumb>Laporan · Pembayaran</x-slot:breadcrumb>
        <x-slot:actions>
            @can('reporting.export')
                <x-ui.button variant="secondary" size="sm" :href="route('reports.payments.export', $filters)">Ekspor CSV</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('reports.payments')">
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_from" label="Dari" :value="$filters['date_from'] ?? ''" />
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_to" label="Sampai" :value="$filters['date_to'] ?? ''" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="clinic_id" label="Klinik">
                <option value="">Semua</option>
                @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.select name="payment_method" label="Metode">
                <option value="">Semua</option>
                @foreach ($methods as $m)<option value="{{ $m }}" @selected(($filters['payment_method'] ?? null) === $m)>{{ $methodLabels[$m] ?? $m }}</option>@endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="received_by" label="Diterima Oleh">
                <option value="">Semua</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected(($filters['received_by'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
            </x-ui.select>
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('reports.payments')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        <span class="rounded-lg bg-success-100 px-3 py-1.5 font-medium text-success-700">Total Diterima: <strong>{{ format_currency_id($summary['total']) }}</strong></span>
        @foreach ($summary['by_method'] as $row)
            <span class="rounded-lg bg-navy-50 px-3 py-1.5 text-ink">{{ $methodLabels[$row->payment_method] ?? $row->payment_method }}: <strong>{{ format_currency_id($row->amount) }}</strong></span>
        @endforeach
    </div>

    <x-ui.card>
        <x-ui.table>
            <thead>
                <tr class="bg-navy-50 text-left text-ink-soft">
                    <th class="px-3 py-2 font-medium">No. Pembayaran</th>
                    <th class="px-3 py-2 font-medium">No. Invoice</th>
                    <th class="px-3 py-2 font-medium">Klinik</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium">Metode</th>
                    <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                    <th class="px-3 py-2 font-medium">Diterima Oleh</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($rows as $r)
                    <tr>
                        <td class="px-3 py-2 font-medium text-navy">{{ $r->payment_number }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $r->invoice_number }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $r->clinic_name }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ format_date_id($r->payment_date) }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $methodLabels[$r->payment_method] ?? $r->payment_method }}</td>
                        <td class="px-3 py-2 text-right font-medium text-navy">{{ format_currency_id($r->amount) }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $r->received_by_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6">
                            <x-ui.empty-state title="Belum ada pembayaran" description="Tidak ada data yang cocok dengan filter saat ini." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <div class="mt-4">{{ $rows->links() }}</div>
</x-settings-shell>
