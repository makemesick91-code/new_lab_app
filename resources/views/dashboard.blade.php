<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="flex">
        {{-- Role-based placeholder sidebar (Sprint 0) --}}
        @include('layouts.sidebar')

        {{-- Main content --}}
        <div class="flex-1 py-8 px-4 sm:px-6 lg:px-8">
            <div class="max-w-5xl mx-auto space-y-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-lg font-medium">{{ __("Welcome to Asia Dental Lab Management System") }}</p>
                        <p class="mt-1 text-sm text-gray-600">{{ __("You're logged in!") }}</p>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="font-semibold text-gray-800">Your access</h3>
                        <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500">Name</dt>
                                <dd class="font-medium">{{ auth()->user()->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Email</dt>
                                <dd class="font-medium">{{ auth()->user()->email }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-gray-500">Roles</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    @forelse(auth()->user()->getRoleNames() as $role)
                                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">{{ $role }}</span>
                                    @empty
                                        <span class="text-gray-400">No role assigned</span>
                                    @endforelse
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs text-gray-400">
                            Feature menus in the sidebar are placeholders. They are wired up in later sprints.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
