<x-settings-shell title="Stock Opname {{ $stockOpname->opname_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Stock Opname Details</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $stockOpname->opname_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $stockOpname->inventoryLocation?->name ?? '-' }} · {{ $stockOpname->opname_date->format('Y-m-d') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('inventory.stock-opnames.review-screen', $stockOpname) }}" class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Review Variance
                </a>
                @if ($stockOpname->status === 'DRAFT')
                    <form method="POST" action="{{ route('inventory.stock-opnames.review', $stockOpname) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            Mark as Ready to Finalize
                        </button>
                    </form>
                @elseif ($stockOpname->status === 'COUNTING')
                    <form method="POST" action="{{ route('inventory.stock-opnames.finalize', $stockOpname) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                            Finalize Opname
                        </button>
                    </form>
                @endif
                @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                    <form method="POST" action="{{ route('inventory.stock-opnames.cancel', $stockOpname) }}">
                        @csrf
                        <div class="flex items-center gap-2">
                            <input type="text" name="notes" placeholder="Reason for cancellation" required
                                   class="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <button type="submit" class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                Cancel
                            </button>
                        </div>
                    </form>
                @endif
                <a href="{{ route('inventory.stock-opnames.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Back
                </a>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Counted Items</h3>
                        @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                            <button type="button" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2" onclick="document.getElementById('add-product-form').classList.toggle('hidden')">
                                Add Product
                            </button>
                        @endif
                    </div>

                    @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                        <div id="add-product-form" class="hidden border-b border-gray-200 p-4">
                            <form method="POST" action="{{ route('inventory.stock-opnames.update-counted-quantity', [$stockOpname, 0]) }}">
                                @csrf
                                <div class="grid gap-4 md:grid-cols-3">
                                    <div class="md:col-span-1">
                                        <label for="new-product" class="block text-sm font-medium text-gray-700">Product <span class="text-red-600">*</span></label>
                                        <select id="new-product" name="product_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                            <option value="">Select a product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="md:col-span-1">
                                        <label for="new-counted" class="block text-sm font-medium text-gray-700">Counted Quantity <span class="text-red-600">*</span></label>
                                        <input id="new-counted" type="number" step="0.01" min="0" name="counted_quantity" required value="0"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                    </div>
                                    <div class="md:col-span-1">
                                        <label for="new-notes" class="block text-sm font-medium text-gray-700">Notes</label>
                                        <input id="new-notes" type="text" name="notes"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                    </div>
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                        Add
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Product</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">System Qty</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Counted Qty</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Variance</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Notes</th>
                                    @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                                        <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($stockOpname->items as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                            <p class="text-xs text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ number_format((float)$item->system_quantity, 2) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">
                                            @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                                                <form method="POST" action="{{ route('inventory.stock-opnames.update-counted-quantity', [$stockOpname, $item->product_id]) }}" class="flex items-center justify-end gap-2">
                                                    @csrf
                                                    <input type="number" step="0.01" min="0" name="counted_quantity" value="{{ number_format((float)$item->counted_quantity, 2) }}"
                                                           class="w-32 rounded-lg border-gray-300 text-sm text-right focus:border-teal-500 focus:ring-teal-500">
                                                    <input type="text" name="notes" value="{{ $item->notes }}" placeholder="Notes"
                                                           class="w-40 rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                                    <button type="submit" class="rounded-lg bg-gray-200 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-300">Save</button>
                                                </form>
                                            @else
                                                {{ number_format((float)$item->counted_quantity, 2) }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums">
                                            <span @class([
                                                'font-semibold',
                                                'text-green-600' => (float)$item->variance_quantity > 0,
                                                'text-red-600' => (float)$item->variance_quantity < 0,
                                                'text-gray-600' => (float)$item->variance_quantity === 0,
                                            ])>
                                                {{ number_format((float)$item->variance_quantity, 2) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">{{ $item->notes ?? '-' }}</td>
                                        @if (in_array($stockOpname->status, ['DRAFT', 'COUNTING']))
                                            <td class="px-4 py-3 text-right">
                                                {{-- Actions can be added here if needed --}}
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-12">
                                            <div class="mx-auto max-w-sm text-center">
                                                <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                                    <span class="text-lg font-semibold">0</span>
                                                </div>
                                                <p class="mt-3 text-sm font-medium text-gray-900">No items yet.</p>
                                                <p class="mt-1 text-sm text-gray-500">Add products to start counting.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Summary</h3>
                    <dl class="mt-4 space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-600">Status</dt>
                            <dd>
                                <span @class([
                                    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
                                    'bg-blue-100 text-blue-800' => $stockOpname->status === 'DRAFT',
                                    'bg-yellow-100 text-yellow-800' => $stockOpname->status === 'COUNTING',
                                    'bg-green-100 text-green-800' => $stockOpname->status === 'COMPLETED',
                                    'bg-red-100 text-red-800' => $stockOpname->status === 'CANCELLED',
                                ])>
                                    {{ ucwords(str_replace('_', ' ', $stockOpname->status)) }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-600">Location</dt>
                            <dd class="text-sm text-gray-900">{{ $stockOpname->inventoryLocation?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-600">Opname Date</dt>
                            <dd class="text-sm text-gray-900">{{ $stockOpname->opname_date->format('Y-m-d') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-600">Counted By</dt>
                            <dd class="text-sm text-gray-900">{{ $stockOpname->countedBy?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-600">Created By</dt>
                            <dd class="text-sm text-gray-900">{{ $stockOpname->createdBy?->name ?? '-' }}</dd>
                        </div>
                        @if ($stockOpname->completed_at)
                            <div class="flex justify-between">
                                <dt class="text-sm text-gray-600">Completed At</dt>
                                <dd class="text-sm text-gray-900">{{ $stockOpname->completed_at->format('Y-m-d H:i') }}</dd>
                            </div>
                        @endif
                        @if ($stockOpname->notes)
                            <div>
                                <dt class="text-sm text-gray-600">Notes</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ $stockOpname->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-settings-shell>
