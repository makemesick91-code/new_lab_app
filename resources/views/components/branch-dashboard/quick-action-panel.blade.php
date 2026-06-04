@props([
    'actions' => [],
])

@php($actions = collect($actions)->filter(fn ($action) => data_get($action, 'href')))

@if ($actions->isNotEmpty())
    <x-branch-dashboard.dashboard-section title="Quick Actions" description="Common branch admin actions." density="compact">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($actions as $action)
                <a href="{{ data_get($action, 'href') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    {{ data_get($action, 'label', 'Open') }}
                </a>
            @endforeach
        </div>
    </x-branch-dashboard.dashboard-section>
@endif
