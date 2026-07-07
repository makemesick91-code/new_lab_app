<x-settings-shell title="Tambah Order Lab">
    <x-ui.page-header title="Tambah Order Lab">
        <x-slot:breadcrumb>Lab / Order Lab / Tambah</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.card>
        <form method="POST" action="{{ route('lab-orders.store') }}" class="space-y-6">
            @csrf
            @include('lab-orders._form', ['order' => null])
            <div class="flex items-center gap-3">
                <x-ui.button type="submit">Simpan Order</x-ui.button>
                <x-ui.button variant="secondary" :href="route('lab-orders.index')">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
