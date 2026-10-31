@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — refund status of
     this invoice's OPEN obligation. Hidden entirely while the flag is off. The
     "Ajukan Refund" link is a courtesy; the service refuses independently. --}}
@if (($panel['enabled'] ?? false) && ($panel['obligation'] ?? null))
    <x-ui.card data-refund-panel>
        <h2 class="text-sm font-semibold text-ink">Refund Kelebihan Bayar</h2>
        <p class="mt-1 text-sm text-ink-soft">
            Kewajiban {{ $panel['obligation']->obligation_number }}:
            <strong class="tabular-nums">{{ OversightFormat::rupiah($panel['obligation']->amount) }}</strong> belum dikembalikan.
        </p>
        @if ($panel['active_request'])
            <p class="mt-2 text-sm" data-refund-panel-active>
                Permintaan <a class="font-mono text-brand-700 hover:underline" href="{{ route('rme.refund-requests.show', $panel['active_request']->id) }}">{{ $panel['active_request']->request_number }}</a>:
                {{ RmeRefundRequestStatus::label($panel['active_request']->status) }}
            </p>
        @elseif ($panel['can_request'])
            <div class="mt-3">
                <x-ui.button :href="route('rme.refund-requests.create', [$visit->id, $invoice->id])">Ajukan Refund</x-ui.button>
            </div>
        @endif
    </x-ui.card>
@endif
