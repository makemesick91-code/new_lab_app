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

        @include('rme.refund-requests.partials.detail', ['showCaseDetail' => true])

        @if (($settlementPanel['enabled'] ?? false) === true)
            <x-ui.card data-refund-settlement-panel>
                <h2 class="text-sm font-semibold text-ink">Pembayaran refund</h2>
                @if ($settlementPanel['holding'] !== null)
                    <p class="mt-1 text-sm">
                        <a class="font-mono text-brand-700 hover:underline" href="{{ route('rme.refund-settlements.show', $settlementPanel['holding']->id) }}">{{ $settlementPanel['holding']->settlement_number }}</a>
                        &middot; {{ \App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus::label($settlementPanel['holding']->status) }}
                    </p>
                @elseif ($settlementPanel['can_start'])
                    <p class="mt-1 text-xs text-ink-soft">Disetujui belum berarti dibayarkan. Mulai pencatatan pembayaran saat Anda akan membayarkan refund ini.</p>
                    <div class="mt-2"><x-ui.button size="sm" :href="route('rme.refund-settlements.create', $refund->id)">Bayarkan Refund</x-ui.button></div>
                @else
                    <p class="mt-1 text-xs text-ink-soft" data-refund-settlement-none>Belum ada pembayaran tercatat untuk permintaan ini.</p>
                @endif
                @if ($settlementPanel['settlements']->where('status', \App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus::ABORTED)->isNotEmpty())
                    <p class="mt-2 text-xs text-ink-muted">Ada pembayaran yang dibatalkan (tidak ada uang keluar) untuk permintaan ini.</p>
                @endif
            </x-ui.card>
        @endif

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
