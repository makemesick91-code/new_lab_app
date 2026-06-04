<section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Admin Cabang</p>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">Daily branch command center</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-600">
                Monitor arrivals, assignments, stuck work, QC, delivery readiness, inventory risk, and unpaid invoices from existing ADLMS modules.
            </p>
        </div>
        <div class="text-left sm:text-right">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Today</p>
            <p class="text-sm font-medium text-gray-900">{{ now()->format('Y-m-d') }}</p>
        </div>
    </div>
</section>

<section aria-labelledby="daily-summary">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 id="daily-summary" class="text-base font-semibold text-gray-900">Daily Summary</h3>
        <p class="text-xs text-gray-500">Branch operations at a glance</p>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($branchSummaryCards as $card)
            <x-branch-dashboard.daily-summary-card
                :label="data_get($card, 'label')"
                :value="data_get($card, 'value', 0)"
                :context="data_get($card, 'context')"
                :severity="data_get($card, 'severity', 'neutral')"
                :href="data_get($card, 'href')"
            />
        @endforeach
    </div>
</section>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <x-branch-dashboard.dashboard-section title="Work Queue Board" description="Daily work organized by branch workflow stage.">
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($branchQueues as $queue)
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-gray-900">{{ data_get($queue, 'title', 'Queue') }}</h4>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-600">{{ collect(data_get($queue, 'items', []))->count() }}</span>
                    </div>
                    <div class="mt-3 space-y-3">
                        @forelse (data_get($queue, 'items', []) as $item)
                            <x-branch-dashboard.queue-card :item="$item" />
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-200 bg-white px-3 py-6 text-center">
                                <p class="text-sm text-gray-500">{{ data_get($queue, 'empty', 'No work items.') }}</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </x-branch-dashboard.dashboard-section>

    <x-owner-dashboard.alert-panel
        :alerts="$branchAlerts"
        title="Alert Center"
        empty-title="No urgent branch alerts"
        empty-body="Overdue orders, stuck work, QC waits, delivery issues, low stock, and unpaid invoices will appear here when supplied."
    />
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <x-branch-dashboard.workload-widget
        title="Production Queue"
        :rows="$productionWorkload"
        :href="$canAny(['view_production', 'manage_production']) ? route('production.board') : null"
        empty-message="No production workload data supplied."
    />

    <x-branch-dashboard.workload-widget
        title="QC Queue"
        :rows="$qcWorkload"
        :href="$canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null"
        empty-message="QC queue is clear or no QC data is supplied."
    />

    <x-branch-dashboard.workload-widget
        title="Delivery Queue"
        :rows="$deliveryWorkload"
        :href="$canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null"
        empty-message="No delivery queue data supplied."
    />
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <x-branch-dashboard.inventory-alert-widget
        :items="$inventoryAlerts"
        :href="$canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null"
    />

    <x-branch-dashboard.finance-alert-widget
        :invoices="$financeAlerts"
        :href="$canAny(['view_invoice', 'manage_invoice']) ? route('invoices.index') : null"
    />
</div>

<x-branch-dashboard.quick-action-panel :actions="$branchQuickActions" />
