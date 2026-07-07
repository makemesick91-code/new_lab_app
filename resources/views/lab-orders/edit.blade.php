<x-settings-shell title="Ubah Order Lab">
    <x-ui.page-header title="Ubah Order Lab" :subtitle="'Nomor Order: '.$order->order_number">
        <x-slot:breadcrumb>Lab / Order Lab / {{ $order->order_number }} / Ubah</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.card>
        <form method="POST" action="{{ route('lab-orders.update', $order) }}" class="space-y-6">
            @csrf @method('PUT')
            @include('lab-orders._form', ['order' => $order])
            <div class="flex items-center gap-3">
                <x-ui.button type="submit">Perbarui Order</x-ui.button>
                <x-ui.button variant="secondary" :href="route('lab-orders.show', $order)">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
