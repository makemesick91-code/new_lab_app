@props(['title' => ''])

<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">DaengtisiaMS</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-900">{{ $title }}</h2>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        {{-- Flash status renders as a global toast (see <x-flash-toasts />) --}}

        @if ($errors->any())
            <div class="rounded-lg border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </div>
</x-app-layout>
