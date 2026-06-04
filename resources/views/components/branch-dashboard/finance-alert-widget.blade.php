@props([
    'invoices' => [],
    'href' => null,
])

@php($invoices = collect($invoices))

<x-branch-dashboard.dashboard-section title="Finance Alerts" description="Unpaid and overdue invoice exposure." :action-href="$href" action-label="Open invoices" density="compact">
    @if ($invoices->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
            <p class="text-sm font-medium text-gray-900">No unpaid invoices needing follow-up</p>
            <p class="mt-1 text-sm text-gray-500">Finance alerts will appear here when invoice data is supplied.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($invoices as $invoice)
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ data_get($invoice, 'number', 'Invoice') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ data_get($invoice, 'clinic', 'Clinic') }}</p>
                        </div>
                        <p class="text-sm font-semibold tabular-nums text-rose-700">{{ data_get($invoice, 'outstanding', 'Rp 0.00') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-branch-dashboard.dashboard-section>
