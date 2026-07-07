@props([
    'delivery',
    'submitLabel' => 'Simpan POD',
    'buttonVariant' => 'primary',
])

<div class="space-y-4 rounded-lg border border-hairline bg-navy-50 p-4">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label for="receiver_name_{{ $submitLabel }}" class="block text-sm font-medium text-ink">
                Nama Penerima <span class="text-danger">*</span>
            </label>
            <input
                type="text"
                id="receiver_name_{{ $submitLabel }}"
                name="receiver_name"
                value="{{ old('receiver_name', $delivery->receiver_name) }}"
                placeholder="Nama penerima"
                required
                class="mt-1 block w-full rounded-lg border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
            >
            @error('receiver_name')
                <p class="mt-1 text-sm text-danger">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="received_at_{{ $submitLabel }}" class="block text-sm font-medium text-ink">
                Waktu Diterima <span class="text-danger">*</span>
            </label>
            <input
                type="datetime-local"
                id="received_at_{{ $submitLabel }}"
                name="received_at"
                value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"
                required
                class="mt-1 block w-full rounded-lg border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
            >
            @error('received_at')
                <p class="mt-1 text-sm text-danger">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @include('deliveries._signature-pad', ['oldValue' => old('receiver_signature_data')])

    <div>
        <label for="delivery_notes_{{ $submitLabel }}" class="block text-sm font-medium text-ink">Catatan Pengiriman</label>
        <textarea
            id="delivery_notes_{{ $submitLabel }}"
            name="delivery_notes"
            rows="3"
            placeholder="Catatan pengiriman (opsional)"
            class="mt-1 block w-full rounded-lg border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
        >{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
        @error('delivery_notes')
            <p class="mt-1 text-sm text-danger">{{ $message }}</p>
        @enderror
    </div>

    <x-ui.button type="submit" :variant="$buttonVariant">{{ $submitLabel }}</x-ui.button>
</div>
