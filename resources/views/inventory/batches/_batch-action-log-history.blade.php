@if ($actionLogHistory->isNotEmpty())
    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Riwayat Tindakan Operasional</h3>
            <p class="text-sm text-gray-500">Catatan audit — tidak mempengaruhi stok ledger.</p>
        </div>
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Waktu</th>
                        <th scope="col" class="px-3 py-3 font-medium">Tindakan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Catatan</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Snapshot Stok</th>
                        <th scope="col" class="px-3 py-3 font-medium">Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($actionLogHistory as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">{{ format_datetime_id($log->acted_at) }}</td>
                            <td class="px-3 py-3">@include('inventory.batches._batch-action-type-badge', ['actionType' => $log->action_type])</td>
                            <td class="px-3 py-3 text-gray-700">{{ $log->note ?? '—' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-600">{{ $log->ledger_quantity_snapshot !== null ? format_quantity_id((float) $log->ledger_quantity_snapshot) : '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $log->actor?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="divide-y divide-gray-100 md:hidden">
            @foreach ($actionLogHistory as $log)
                <article class="p-4 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @include('inventory.batches._batch-action-type-badge', ['actionType' => $log->action_type])
                        <span class="text-xs text-gray-500">{{ format_datetime_id($log->acted_at) }}</span>
                    </div>
                    @if ($log->note)
                        <p class="mt-2 text-gray-700">{{ $log->note }}</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-500">
                        Snapshot stok: {{ $log->ledger_quantity_snapshot !== null ? format_quantity_id((float) $log->ledger_quantity_snapshot) : '—' }}
                        · {{ $log->actor?->name ?? '—' }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
