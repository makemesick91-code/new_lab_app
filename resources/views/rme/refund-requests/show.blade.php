{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — one refund request.
     The buttons come from RmeRefundRequestService::actionsFor(); the service
     refuses every action again on submit. --}}
<x-settings-shell title="Permintaan Refund">
    <div class="space-y-6">
        <x-ui.page-header :title="'Permintaan Refund '.$refund->request_number" subtitle="Keputusan atas pengembalian kelebihan bayar. Tidak ada uang yang dibayarkan dari halaman ini.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.refund-requests.index')">&larr; Daftar Permintaan</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" data-refund-errors>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @include('rme.refund-requests.partials.detail')

        @if ($actions['approve'] || $actions['reject'])
            <x-ui.card data-refund-decide>
                <form method="POST" action="{{ route('rme.refund-requests.approve', $refund->id) }}" class="space-y-2" data-refund-approve>
                    @csrf
                    <x-ui.textarea name="decision_note" label="Catatan persetujuan (opsional)" rows="2" />
                    <x-ui.button type="submit">Setujui Refund</x-ui.button>
                </form>
                <form method="POST" action="{{ route('rme.refund-requests.reject', $refund->id) }}" class="mt-4 space-y-2" data-refund-reject>
                    @csrf
                    <x-ui.textarea name="decision_note" label="Alasan penolakan (10–1000 karakter)" rows="2" required />
                    <x-ui.button type="submit" variant="danger">Tolak</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($actions['withdraw'] || $actions['revoke'])
            <x-ui.card data-refund-cancel>
                <form method="POST" action="{{ route('rme.refund-requests.cancel', $refund->id) }}" class="space-y-2">
                    @csrf
                    <x-ui.textarea name="cancel_reason" :label="$actions['revoke'] ? 'Alasan pencabutan persetujuan (10–1000 karakter)' : 'Alasan penarikan (10–1000 karakter)'" rows="2" required />
                    <x-ui.button type="submit" variant="secondary">{{ $actions['revoke'] ? 'Cabut Persetujuan' : 'Tarik Permintaan' }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
