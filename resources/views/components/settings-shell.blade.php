@props(['title' => ''])

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $title }}</h2>
    </x-slot>

    <div class="flex">
        @include('layouts.sidebar')

        <div class="flex-1 py-8 px-4 sm:px-6 lg:px-8">
            <div class="max-w-5xl mx-auto space-y-6">
                @if (session('status'))
                    <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>
</x-app-layout>
