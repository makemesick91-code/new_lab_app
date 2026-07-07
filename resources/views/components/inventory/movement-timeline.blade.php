@props([
    'movements' => [],
    'href' => null,
])

@php($movements = collect($movements))

<x-inventory.dashboard-section title="Pergerakan Terbaru" description="Pergerakan ledger persediaan terbaru di cabang ini, ditampilkan sebagai timeline." :action-href="$href" action-label="Buka stok">
    @if ($movements->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center" data-testid="inventory-dashboard-recent-movements-empty">
            <p class="text-sm text-slate-600">Belum ada mutasi stok terbaru untuk cabang ini.</p>
        </div>
    @else
        <ol class="relative space-y-4 border-l border-gray-200 pl-4">
            @foreach ($movements as $movement)
                @php($delta = (float) $movement->quantity_in - (float) $movement->quantity_out)
                @php($isInbound = $delta >= 0)
                <li class="relative">
                    <span class="absolute -left-[1.3125rem] mt-1.5 h-2.5 w-2.5 rounded-full {{ $isInbound ? 'bg-success-700' : 'bg-warning-700' }}"></span>
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $movement->product?->name ?? '-' }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ format_date_id($movement->movement_date) }} · {{ $movement->inventoryLocation?->name ?? '-' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums {{ $isInbound ? 'text-success-700' : 'text-warning-700' }}">
                                    {{ $delta >= 0 ? '+' : '' }}{{ format_quantity_id($delta) }}
                                </p>
                                <p class="text-xs text-gray-500">{{ str_replace('_', ' ', $movement->movement_type) }}</p>
                            </div>
                        </div>
                        @if ($movement->notes)
                            <p class="mt-2 text-xs text-gray-500">{{ $movement->notes }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</x-inventory.dashboard-section>
