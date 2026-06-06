@php
    use App\Modules\Inventory\Models\PurchaseRequest;
@endphp

<x-settings-shell title="Permintaan Pembelian {{ $purchaseRequest->purchase_request_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Permintaan Pembelian</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $purchaseRequest->purchase_request_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ format_date_id($purchaseRequest->request_date) }}
                    · @include('inventory.purchase-requests._status-badge', ['status' => $purchaseRequest->status])
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('update', $purchaseRequest)
                    <a href="{{ route('inventory.purchase-requests.edit', $purchaseRequest) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Ubah
                    </a>
                @endcan

                @can('submit', $purchaseRequest)
                    <form method="POST" action="{{ route('inventory.purchase-requests.submit', $purchaseRequest) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            Ajukan
                        </button>
                    </form>
                @endcan

                @can('approve', $purchaseRequest)
                    <form method="POST" action="{{ route('inventory.purchase-requests.approve', $purchaseRequest) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Setujui
                        </button>
                    </form>
                @endcan

                @can('reject', $purchaseRequest)
                    <form method="POST" action="{{ route('inventory.purchase-requests.reject', $purchaseRequest) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="text" name="rejection_reason" placeholder="Alasan penolakan" value="{{ old('rejection_reason') }}" required minlength="3"
                               class="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <button type="submit" class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                            Tolak
                        </button>
                    </form>
                @endcan

                @can('cancel', $purchaseRequest)
                    <form method="POST" action="{{ route('inventory.purchase-requests.cancel', $purchaseRequest) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-gray-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            Batalkan
                        </button>
                    </form>
                @endcan

                <a href="{{ route('inventory.purchase-requests.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali
                </a>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Permintaan</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Harga Estimasi</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Catatan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($purchaseRequest->items as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                            <p class="text-xs text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $item->inventoryLocation?->name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->quantity_requested) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                            {{ $item->estimated_unit_price !== null ? format_currency_id($item->estimated_unit_price) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">{{ $item->notes ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Belum ada item.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Informasi Permintaan</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">Pemohon</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseRequest->requestedBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Dibuat oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseRequest->createdBy?->name ?? '—' }}</dd>
                        </div>
                        @if ($purchaseRequest->notes)
                            <div>
                                <dt class="text-gray-500">Catatan</dt>
                                <dd class="text-gray-900">{{ $purchaseRequest->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                @if ($purchaseRequest->isApproved())
                    <div class="rounded-lg border border-green-200 bg-green-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-green-900">Persetujuan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-green-800">
                            <div>
                                <dt class="font-medium">Disetujui oleh</dt>
                                <dd>{{ $purchaseRequest->approvedBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Waktu persetujuan</dt>
                                <dd>{{ $purchaseRequest->approved_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if ($purchaseRequest->isRejected())
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-red-900">Penolakan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-red-800">
                            <div>
                                <dt class="font-medium">Ditolak oleh</dt>
                                <dd>{{ $purchaseRequest->rejectedBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Waktu penolakan</dt>
                                <dd>{{ $purchaseRequest->rejected_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Alasan</dt>
                                <dd>{{ $purchaseRequest->rejection_reason ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-settings-shell>
