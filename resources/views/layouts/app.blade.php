<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Daengtisia Management System') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        class="h-full font-sans antialiased bg-gray-100 text-gray-900"
        x-data
        x-init="
            const onResize = () => {
                if (window.innerWidth < 1280) {
                    $store.sidebar.setMobileOpen(false);
                }
            };
            window.addEventListener('resize', onResize);
        "
    >
        <div class="min-h-screen xl:flex">
            @include('layouts.partials.backdrop')
            @include('layouts.sidebar')

            <div
                class="flex min-h-screen min-w-0 flex-1 flex-col transition-[margin] duration-300 ease-in-out"
                :class="$store.sidebar.isExpanded ? 'xl:ml-[290px]' : 'xl:ml-0'"
            >
                @include('layouts.partials.topbar')

                <main class="flex-1 bg-gray-50 px-4 py-6 sm:px-6 lg:px-8 xl:px-[2cm]">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
