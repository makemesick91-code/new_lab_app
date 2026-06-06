<x-settings-shell title="Buat Invoice">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="GET" action="{{ route('invoices.create') }}" class="flex flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="No. order, klinik, dokter, pasien"
                       class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">Semua klinik</option>
                    @foreach ($clinics as $clinic)
                        <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('invoices.create') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
        </div>

        <form method="POST" action="{{ route('invoices.store') }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-5">
            @csrf

            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Klinik</label>
                    <select name="clinic_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Pilih klinik</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected(old('clinic_id', $filters['clinic_id']) == $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                    @error('clinic_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Invoice</label>
                    <input type="date" name="invoice_date" value="{{ old('invoice_date', now()->toDateString()) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    @error('invoice_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Jatuh Tempo</label>
                    <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(14)->toDateString()) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    @error('due_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Diskon</label>
                    <input type="number" step="0.01" min="0" name="discount_amount" value="{{ old('discount_amount', 0) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    @error('discount_amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Catatan</label>
                <textarea name="notes" rows="2" class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('notes') }}</textarea>
            </div>

            @error('lab_order_ids') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Pilih</th>
                            <th class="px-3 py-2 font-medium">No. Order</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Item</th>
                            <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($availableOrders as $order)
                            <tr>
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="lab_order_ids[]" value="{{ $order->id }}" class="rounded border-gray-300 text-indigo-600">
                                </td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->patient?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->items->count() }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($order->items->sum('subtotal')) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada Order Lab selesai yang dapat dibuat invoice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('invoices.index') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Batal</a>
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Buat Draft</button>
            </div>
        </form>
    </div>
</x-settings-shell>
