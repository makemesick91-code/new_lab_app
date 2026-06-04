@props([
    'title' => 'Workload',
    'rows' => [],
    'href' => null,
    'emptyMessage' => 'No workload data available.',
])

@php($rows = collect($rows))

<x-branch-dashboard.dashboard-section :title="$title" description="Current branch workload and bottleneck indicators." :action-href="$href" action-label="Open board" density="compact">
    @if ($rows->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
            <p class="text-sm text-gray-500">{{ $emptyMessage }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($rows as $row)
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-gray-900">{{ data_get($row, 'name', 'Team') }}</p>
                        <p class="text-xs text-gray-500">{{ data_get($row, 'status', 'Active') }}</p>
                    </div>
                    <dl class="mt-3 grid grid-cols-4 gap-2 text-xs">
                        <div>
                            <dt class="text-gray-500">Assigned</dt>
                            <dd class="font-semibold tabular-nums text-gray-900">{{ data_get($row, 'assigned', 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Progress</dt>
                            <dd class="font-semibold tabular-nums text-gray-900">{{ data_get($row, 'in_progress', 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Paused</dt>
                            <dd class="font-semibold tabular-nums text-amber-700">{{ data_get($row, 'paused', 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Overdue</dt>
                            <dd class="font-semibold tabular-nums text-rose-700">{{ data_get($row, 'overdue', 0) }}</dd>
                        </div>
                    </dl>
                </div>
            @endforeach
        </div>
    @endif
</x-branch-dashboard.dashboard-section>
