{{--
    IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — owner OPS-OQ-1 (rule
    203). The billing dispute panel of the cashier invoice page.

    A cashier who finds that this invoice's amount is wrong REPORTS it here. The
    report never changes the invoice; it holds collection on every payment path
    and escalates the case to the branch authority. Buttons are a courtesy: the
    policy and RmeBillingDisputeService refuse independently.
--}}
@php
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
@endphp
<x-ui.card title="Ketidaksesuaian Tagihan" data-billing-dispute-panel>
    @if ($panel['active'])
        <x-ui.alert variant="danger" data-billing-dispute-active>
            Laporan ketidaksesuaian aktif:
            <a class="font-semibold underline" href="{{ route('rme.billing-disputes.show', $panel['active']->id) }}">{{ $panel['active']->dispute_number }}</a>
            — {{ RmeBillingDisputeStatus::label($panel['active']->status) }}
            ({{ RmeBillingDisputeReason::label($panel['active']->reason_category) }}).
            Tagihan tidak diubah. Pembayaran ditahan sampai penanggung jawab cabang menyelesaikan laporan ini.
            Jangan menagih nominal yang diketahui salah dan jangan menahan pasien.
        </x-ui.alert>
    @else
        <p class="text-sm text-ink-soft">
            Bila nominal tagihan ini diketahui salah, jangan tagih nominal tersebut dan jangan ubah tagihan sendiri.
            Laporkan ketidaksesuaian agar pembayaran ditahan dan kasus diteruskan ke penanggung jawab cabang.
        </p>
        @if ($panel['can_report'])
            <div class="mt-3">
                <x-ui.button variant="secondary" :href="route('rme.billing-disputes.create', [$visit, $invoice])" data-billing-dispute-report-button>
                    Laporkan Ketidaksesuaian Tagihan
                </x-ui.button>
            </div>
        @endif
    @endif

    @if ($panel['history']->isNotEmpty())
        <ul class="mt-3 space-y-1 text-sm">
            @foreach ($panel['history'] as $dispute)
                <li>
                    <a class="text-brand-700 hover:underline" href="{{ route('rme.billing-disputes.show', $dispute->id) }}">{{ $dispute->dispute_number }}</a>
                    <x-ui.badge :tone="RmeBillingDisputeStatus::tone($dispute->status)">{{ RmeBillingDisputeStatus::label($dispute->status) }}</x-ui.badge>
                    <span class="text-ink-soft">{{ RmeBillingDisputeOutcome::label($dispute->resolution_outcome) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
