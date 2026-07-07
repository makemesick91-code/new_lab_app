@props(['resetUrl'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2 sm:flex-row md:col-span-2 xl:col-span-3 xl:items-end xl:justify-end']) }}>
    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2 sm:w-auto">
        Terapkan
    </button>
    <a href="{{ $resetUrl }}" class="inline-flex w-full justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto">
        Atur Ulang
    </a>
</div>
