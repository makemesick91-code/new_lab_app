@php
    $onlineContextService = app(\App\Modules\RmeOnlineContext\Services\UserOnlineContextService::class);
    $user = auth()->user();
    $context = $user ? $onlineContextService->currentContextFor($user) : null;
    $isDoctorOnline = $user && $onlineContextService->isDoctorOnline($user);
    $isAdminActive = $user && $onlineContextService->isAdminClinicActive($user);
    $isPerawatActive = $user && $onlineContextService->isPerawatActive($user);
@endphp

@if ($isDoctorOnline || $isAdminActive || $isPerawatActive)
    <div class="hidden items-center gap-2 lg:flex" data-testid="rme-online-context-badge">
        @if ($isDoctorOnline)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800">
                <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                Dokter Online
                <span class="text-emerald-700">
                    {{ $context?->branch?->code }} · {{ $context?->clinicRoom?->name }}
                </span>
            </span>
        @elseif ($isAdminActive)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800">
                <span class="h-2 w-2 rounded-full bg-sky-500" aria-hidden="true"></span>
                Admin Klinik Aktif
                <span class="text-sky-700">{{ $context?->branch?->code }} — {{ $context?->branch?->name }}</span>
            </span>
        @elseif ($isPerawatActive)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800">
                <span class="h-2 w-2 rounded-full bg-sky-500" aria-hidden="true"></span>
                Perawat Aktif
                <span class="text-sky-700">{{ $context?->branch?->code }} — {{ $context?->branch?->name }}</span>
            </span>
        @endif

        <div class="flex items-center gap-1">
            <a href="{{ route('rme.online-context.select') }}"
                class="rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900">
                Ganti
            </a>
            <form method="POST" action="{{ route('rme.online-context.offline') }}" class="inline">
                @csrf
                <button type="submit"
                    class="rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900">
                    Offline
                </button>
            </form>
        </div>
    </div>
@endif
