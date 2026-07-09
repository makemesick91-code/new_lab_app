{{--
    FIX-PRE-68-45 Scope G / SPRINT-68.45 Scope B — one PR list section for the
    branch workflow board.
    Expects: $rows (Collection of PurchaseRequest), $emptyText, $typeLabel,
             $typeTone, $statusBadge (closures), $canProcessPr (bool).
    $statusBadge is optional for backward compatibility.
--}}
@php $statusBadge = $statusBadge ?? null; @endphp
@if ($rows->isEmpty())
    <x-ui.empty-state title="{{ $emptyText }}" />
@else
    <x-ui.table>
        <thead>
            <tr class="bg-navy-50 text-left text-ink-soft">
                <th class="px-3 py-2">No. PR</th>
                <th class="px-3 py-2">Tipe</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Tanggal</th>
                <th class="px-3 py-2">Pemohon</th>
                <th class="px-3 py-2 text-right">Item</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
            @foreach ($rows as $pr)
                @php [$statusText, $statusTone] = $statusBadge ? $statusBadge($pr) : [ucfirst((string) $pr->status), 'neutral']; @endphp
                <tr>
                    <td class="px-3 py-2 font-medium text-navy">{{ $pr->purchase_request_number }}</td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$typeTone($pr)">{{ $typeLabel($pr) }}</x-ui.badge></td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$statusTone">{{ $statusText }}</x-ui.badge></td>
                    <td class="px-3 py-2 text-ink">{{ optional($pr->request_date)->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-2 text-ink">{{ $pr->requestedBy?->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($pr->items_count ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">
                        <x-ui.button variant="secondary" size="sm" :href="route('inventory.purchase-requests.show', $pr)">
                            {{ $canProcessPr ? 'Proses' : 'Lihat' }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
@endif
