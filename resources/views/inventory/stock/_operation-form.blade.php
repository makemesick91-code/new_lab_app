@php($includeCost = $includeCost ?? false)
@php($includeSupplier = $includeSupplier ?? false)

<div class="bg-white shadow-sm sm:rounded-lg p-6">
    <div class="mb-5">
        <p class="text-sm text-gray-500">{{ $product->code }} - {{ $product->category?->name ?? '-' }}</p>
        <h3 class="text-xl font-semibold text-gray-900">{{ $product->name }}</h3>
        <p class="mt-1 text-sm text-gray-500">Unit: {{ $product->unit?->symbol ?? '-' }}</p>
    </div>

    <form method="POST" action="{{ $action }}" class="space-y-5">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700">Inventory Location</label>
                <select name="inventory_location_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    <option value="">Select location</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(old('inventory_location_id') == $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Quantity</label>
                <input type="number" step="0.01" min="0.01" name="quantity" value="{{ old('quantity') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            </div>
            @if ($includeCost)
                <div>
                    <label class="block text-sm font-medium text-gray-700">Unit Cost</label>
                    <input type="number" step="0.01" min="0" name="unit_cost" value="{{ old('unit_cost', 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            @endif
            @if ($includeSupplier)
                <div>
                    <label class="block text-sm font-medium text-gray-700">Supplier</label>
                    <select name="supplier_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">No supplier</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Notes</label>
                <textarea name="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('inventory.products.show', $product) }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ $button }}</button>
        </div>
    </form>
</div>
