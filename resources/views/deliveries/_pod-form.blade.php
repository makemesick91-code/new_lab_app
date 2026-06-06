@props([
    'delivery',
    'submitLabel' => 'Simpan POD',
    'buttonClass' => 'bg-teal-700 hover:bg-teal-600 focus:ring-teal-500',
])

<div class="space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label for="receiver_name_{{ $submitLabel }}" class="block text-sm font-medium text-gray-700">
                Nama Penerima <span class="text-rose-600">*</span>
            </label>
            <input
                type="text"
                id="receiver_name_{{ $submitLabel }}"
                name="receiver_name"
                value="{{ old('receiver_name', $delivery->receiver_name) }}"
                placeholder="Nama penerima"
                required
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
            @error('receiver_name')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="received_at_{{ $submitLabel }}" class="block text-sm font-medium text-gray-700">
                Waktu Diterima <span class="text-rose-600">*</span>
            </label>
            <input
                type="datetime-local"
                id="received_at_{{ $submitLabel }}"
                name="received_at"
                value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"
                required
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
            @error('received_at')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @include('deliveries._signature-pad', ['oldValue' => old('receiver_signature_data')])

    <div>
        <label for="delivery_notes_{{ $submitLabel }}" class="block text-sm font-medium text-gray-700">Catatan Pengiriman</label>
        <textarea
            id="delivery_notes_{{ $submitLabel }}"
            name="delivery_notes"
            rows="3"
            placeholder="Catatan pengiriman (opsional)"
            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
        >{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
        @error('delivery_notes')
            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <button
        type="submit"
        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $buttonClass }}"
    >
        {{ $submitLabel }}
    </button>
</div>
