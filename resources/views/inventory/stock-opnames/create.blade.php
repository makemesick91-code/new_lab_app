<x-settings-shell title="Create Stock Opname">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">New Stock Opname</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Create Stock Opname Session</h2>
            <p class="mt-1 text-sm text-gray-500">Start a physical count for a specific inventory location.</p>
        </div>

        <form method="POST" action="{{ route('inventory.stock-opnames.store') }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <label for="inventory-location" class="block text-sm font-medium text-gray-700">Inventory Location <span class="text-red-600">*</span></label>
                    <select id="inventory-location" name="inventory_location_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Select a location</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(old('inventory_location_id') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('inventory_location_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="opname-date" class="block text-sm font-medium text-gray-700">Opname Date <span class="text-red-600">*</span></label>
                    <input id="opname-date" type="date" name="opname_date" required value="{{ old('opname_date', now()->toDateString()) }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    @error('opname_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="product-ids" class="block text-sm font-medium text-gray-700">Products to Count</label>
                    <select id="product-ids" name="product_ids[]" multiple class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" size="8">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(in_array($product->id, old('product_ids', [])))>{{ $product->code }} - {{ $product->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Leave blank to add products later, or select multiple products to count.</p>
                    @error('product_ids')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('product_ids.*')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                    <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.stock-opnames.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Cancel
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Create Stock Opname
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
