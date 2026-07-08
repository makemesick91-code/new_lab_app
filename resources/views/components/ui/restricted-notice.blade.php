@props([
    'title' => 'Akses terbatas',
    'description' => 'Anda tidak memiliki akses untuk tindakan ini. Hubungi administrator jika memerlukan akses.',
])

{{--
    UIX-20 — Canonical permission-aware "restricted action" notice.

    Presentation only and intentionally non-submitting: it never renders an
    interactive submit control. It explains, in place, why an action is
    not available to the current operator. Server-side route middleware, policies,
    Gates, Spatie Permission, and BranchContext remain the authoritative boundary;
    this notice must only be rendered inside an existing @cannot/@else branch of a
    real @can/@canany guard — it does not perform authorization itself.
--}}
<div
    {{ $attributes->merge(['class' => 'inline-flex max-w-md items-start gap-2 rounded-lg border border-hairline bg-navy-50 px-3 py-2 text-left']) }}
    role="note"
>
    <svg class="mt-0.5 h-4 w-4 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-1.5 0h12a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5H6a1.5 1.5 0 0 1-1.5-1.5v-6a1.5 1.5 0 0 1 1.5-1.5Z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-medium text-ink">{{ $title }}</p>
        @if ($description)
            <p class="mt-0.5 text-xs text-ink-soft">{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
