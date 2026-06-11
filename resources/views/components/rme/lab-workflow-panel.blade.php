@props([
    'invoice',
    'candidates',
    'compact' => false,
])

@php
    $pendingCount = $candidates->where('status', \App\Modules\LabOrder\Models\LabCaseCandidate::STATUS_PENDING_REVIEW)->count();
    $convertedCount = $candidates->where('status', \App\Modules\LabOrder\Models\LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)->count();
    $totalCount = $candidates->count();
@endphp

@if ($invoice->isPaid() || $totalCount > 0)
    <x-ui.card :title="$compact ? null : 'Status Pekerjaan Lab RME'" {{ $attributes->merge(['class' => trim(($attributes->get('class') ?? '').' print:break-inside-avoid')]) }}>
        @if ($compact)
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Kandidat Lab RME</h3>
        @endif

        @if ($totalCount > 0)
            <div class="mb-4 flex flex-wrap gap-3 text-sm">
                <span class="rounded-md bg-gray-100 px-2.5 py-1 text-gray-700">
                    <span class="font-semibold">{{ $totalCount }}</span> kandidat
                </span>
                @if ($pendingCount > 0)
                    <span class="rounded-md bg-amber-50 px-2.5 py-1 text-amber-800">
                        <span class="font-semibold">{{ $pendingCount }}</span> menunggu review
                    </span>
                @endif
                @if ($convertedCount > 0)
                    <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-emerald-800">
                        <span class="font-semibold">{{ $convertedCount }}</span> dikonversi
                    </span>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="pb-2 pr-3">Tindakan</th>
                            <th class="pb-2 pr-3">Status</th>
                            <th class="pb-2 pr-3 text-right">Estimasi</th>
                            <th class="pb-2 text-right">Tautan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($candidates as $candidate)
                            <tr>
                                <td class="py-2 pr-3 text-gray-900">
                                    {{ $candidate->source_description ?? $candidate->treatment?->name ?? '—' }}
                                </td>
                                <td class="py-2 pr-3">
                                    <x-ui.badge :tone="$candidate->statusTone()">
                                        {{ $candidate->statusLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="py-2 pr-3 text-right text-gray-700 whitespace-nowrap">
                                    {{ $candidate->displayEstimatedPrice() }}
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @can('view', $candidate)
                                        <a href="{{ route('lab-case-candidates.show', $candidate) }}"
                                           class="text-teal-700 hover:text-teal-900 hover:underline">
                                            Kandidat #{{ $candidate->id }}
                                        </a>
                                    @else
                                        <span class="text-gray-500">Kandidat #{{ $candidate->id }}</span>
                                    @endcan
                                    @if ($candidate->isConverted() && $candidate->convertedLabOrder)
                                        <span class="mx-1 text-gray-300">|</span>
                                        @can('view', $candidate->convertedLabOrder)
                                            <a href="{{ route('lab-orders.show', $candidate->convertedLabOrder) }}"
                                               class="font-mono text-teal-700 hover:text-teal-900 hover:underline">
                                                {{ $candidate->convertedLabOrder->order_number }}
                                            </a>
                                        @else
                                            <span class="font-mono text-gray-600">{{ $candidate->convertedLabOrder->order_number }}</span>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-600">
                Belum ada kandidat pekerjaan lab untuk tagihan ini. Kandidat hanya dibuat untuk tindakan yang membutuhkan pekerjaan lab setelah pembayaran lunas.
            </p>
        @endif
    </x-ui.card>
@endif
