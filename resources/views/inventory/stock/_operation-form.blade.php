@php
    $includeCost = $includeCost ?? false;
    $includeSupplier = $includeSupplier ?? false;
    $operationType = $operationType ?? 'opening';
    $locations = collect($locations ?? []);
    $suppliers = collect($suppliers ?? []);
    $hasLocations = $locations->isNotEmpty();

    $operationCopy = [
        'opening' => [
            'eyebrow' => 'Opening Stock',
            'title' => 'Create Initial Ledger Entry',
            'description' => 'Record the initial quantity for this product at one Inventory Location.',
            'bannerTone' => 'info',
            'bannerTitle' => 'Opening Stock creates an initial ledger movement.',
            'bannerText' => 'Use this only for setup or approved stock initialization. It adds quantity into the selected location.',
            'locationHelp' => 'Choose where this initial stock physically exists.',
            'quantityHelp' => 'Enter the initial quantity. It must be greater than 0.',
            'costHelp' => 'Use the known opening unit cost. Use 0 only when no cost is captured.',
            'notesHelp' => 'Add setup reference or approval notes when available.',
            'buttonTone' => 'teal',
        ],
        'receive' => [
            'eyebrow' => 'Receive Stock',
            'title' => 'Receive Stock Into Location',
            'description' => 'Record inbound stock from an optional supplier into a specific Inventory Location.',
            'bannerTone' => 'success',
            'bannerTitle' => 'Receive Stock increases ledger quantity.',
            'bannerText' => 'Supplier and unit cost help finance and audit review later, even when supplier is optional.',
            'locationHelp' => 'Stock will be added to this selected location only.',
            'quantityHelp' => 'Enter received quantity. It must be greater than 0.',
            'costHelp' => 'Capture supplier unit cost when known. Use 0 only when cost is not available.',
            'notesHelp' => 'Add delivery note, invoice number, or receive context.',
            'buttonTone' => 'emerald',
        ],
        'adjust_in' => [
            'eyebrow' => 'Adjustment In',
            'title' => 'Increase Stock By Correction',
            'description' => 'Record a positive stock correction without supplier purchase context.',
            'bannerTone' => 'warning',
            'bannerTitle' => 'Adjustment In is for stock correction.',
            'bannerText' => 'Use Receive Stock for supplier deliveries. Adjustment notes should explain why this correction is needed.',
            'locationHelp' => 'Choose the location where corrected stock should increase.',
            'quantityHelp' => 'Enter correction quantity. It must be greater than 0.',
            'costHelp' => null,
            'notesHelp' => 'Explain the reason for this correction.',
            'buttonTone' => 'teal',
        ],
        'adjust_out' => [
            'eyebrow' => 'Adjustment Out',
            'title' => 'Reduce Stock By Correction',
            'description' => 'Record a negative stock correction at one Inventory Location.',
            'bannerTone' => 'danger',
            'bannerTitle' => 'Adjustment Out reduces stock and cannot be treated casually.',
            'bannerText' => 'The system rejects this action if the selected location has insufficient stock. Confirm the location before submitting.',
            'locationHelp' => 'Stock will be reduced from this selected location only.',
            'quantityHelp' => 'Enter reduction quantity. It must be greater than 0 and cannot exceed location stock.',
            'costHelp' => null,
            'notesHelp' => 'Explain the reason for reducing stock.',
            'buttonTone' => 'amber',
        ],
    ][$operationType] ?? [
        'eyebrow' => 'Inventory Movement',
        'title' => 'Record Inventory Movement',
        'description' => 'Record stock movement into the ledger.',
        'bannerTone' => 'info',
        'bannerTitle' => 'Stock is ledger-derived.',
        'bannerText' => 'Every movement changes calculated stock through the ledger.',
        'locationHelp' => 'Choose the physical location for this stock movement.',
        'quantityHelp' => 'Quantity must be greater than 0.',
        'costHelp' => null,
        'notesHelp' => 'Add movement notes when available.',
        'buttonTone' => 'teal',
    ];

    $bannerClasses = [
        'info' => 'border-sky-200 bg-sky-50 text-sky-800',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-800',
    ][$operationCopy['bannerTone']];

    $buttonClasses = [
        'teal' => 'bg-teal-700 hover:bg-teal-600 focus:ring-teal-500',
        'emerald' => 'bg-emerald-700 hover:bg-emerald-600 focus:ring-emerald-500',
        'amber' => 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500',
    ][$operationCopy['buttonTone']];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ $operationCopy['eyebrow'] }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $operationCopy['title'] }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $operationCopy['description'] }}</p>
        </div>
        <a href="{{ route('inventory.products.show', $product) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
            Cancel
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <aside class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Product Summary Panel</p>
                <h3 class="mt-2 text-lg font-semibold text-gray-900">{{ $product->name }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $product->code }} - {{ $product->category?->name ?? 'No category' }}</p>

                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Unit</dt>
                        <dd class="font-semibold text-gray-900">{{ $product->unit?->symbol ?? '-' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Minimum Stock</dt>
                        <dd class="font-semibold tabular-nums text-gray-900">{{ number_format((float) $product->minimum_stock, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Average Cost</dt>
                        <dd class="font-semibold tabular-nums text-gray-900">{{ number_format((float) $product->average_cost, 2) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-teal-100 bg-teal-50 p-5 text-sm text-teal-800">
                <p class="font-semibold text-teal-900">Ledger-derived stock</p>
                <p class="mt-1">This form creates one inventory movement. Current stock is calculated from all ledger movements, not stored as a mutable product value.</p>
            </section>
        </aside>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 p-5">
                <div class="rounded-lg border p-4 text-sm {{ $bannerClasses }}">
                    <p class="font-semibold">{{ $operationCopy['bannerTitle'] }}</p>
                    <p class="mt-1">{{ $operationCopy['bannerText'] }}</p>
                </div>
            </div>

            @if (! $hasLocations)
                <div class="p-6">
                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-10 text-center">
                        <p class="text-sm font-semibold text-gray-900">No active Inventory Location is available.</p>
                        <p class="mt-1 text-sm text-gray-500">Create or activate an Inventory Location before recording stock movements.</p>
                        <a href="{{ route('inventory.locations.index') }}" class="mt-4 inline-flex rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Open Locations
                        </a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ $action }}" class="space-y-6 p-5">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="inventory_location_id" class="text-sm font-semibold text-gray-800">Inventory Location <span class="text-rose-600">*</span></label>
                            <select id="inventory_location_id" name="inventory_location_id" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" required>
                                <option value="">Select physical stock location</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected(old('inventory_location_id') == $location->id)>
                                        {{ $location->name }}{{ $location->code ? ' ('.$location->code.')' : '' }} - {{ str_replace('_', ' ', $location->type) }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['locationHelp'] }}</p>
                            @error('inventory_location_id')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach ($locations->take(4) as $location)
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <p class="text-sm font-semibold text-gray-900">{{ $location->name }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500">{{ $location->code ?: 'No code' }} - {{ str_replace('_', ' ', $location->type) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label for="quantity" class="text-sm font-semibold text-gray-800">Quantity <span class="text-rose-600">*</span></label>
                            <input id="quantity" type="number" step="0.01" min="0.01" name="quantity" value="{{ old('quantity') }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500" required>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['quantityHelp'] }}</p>
                            @error('quantity')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        @if ($includeCost)
                            <div>
                                <label for="unit_cost" class="text-sm font-semibold text-gray-800">Unit Cost</label>
                                <input id="unit_cost" type="number" step="0.01" min="0" name="unit_cost" value="{{ old('unit_cost', 0) }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                                <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['costHelp'] }}</p>
                                @error('unit_cost')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if ($includeSupplier)
                            <div class="md:col-span-2">
                                <label for="supplier_id" class="text-sm font-semibold text-gray-800">Supplier</label>
                                <select id="supplier_id" name="supplier_id" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                    <option value="">No supplier selected</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Optional, but useful for receive stock audit and future supplier review.</p>
                                @error('supplier_id')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        <div class="md:col-span-2">
                            <label for="notes" class="text-sm font-semibold text-gray-800">Notes / Reason</label>
                            <textarea id="notes" name="notes" rows="4" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['notesHelp'] }}</p>
                            @error('notes')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-gray-500">Review product, location, quantity, and cost before submitting. This creates an auditable stock ledger movement.</p>
                        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <a href="{{ route('inventory.products.show', $product) }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Cancel
                            </a>
                            <button class="inline-flex justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $buttonClasses }}">
                                {{ $button }}
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </section>
    </div>
</div>
