<x-settings-shell title="Ubah Metode Pembayaran">
    <div class="rounded-xl border border-hairline bg-surface shadow-card">
        <form method="POST" action="{{ route('settings.payment-methods.update', $paymentMethod) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.payment-methods._form', ['paymentMethod' => $paymentMethod])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Perbarui Metode</button>
                <a href="{{ route('settings.payment-methods.index') }}" class="text-sm text-ink-soft hover:text-navy">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
