@props([
    'presence' => [],
])

{{--
    DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — ruling P11's presence cell.

    The STATE IS CARRIED BY THE TEXT, never by colour alone: "SEDANG ONLINE" and
    "Offline" say it outright, and the tone only reinforces it.

    It shows the branch and the room because the warning has to be specific — an
    approver about to end a doctor's session should see that the doctor is in a
    consultation room right now. It shows NO device name, NO IP, NO user agent
    and NO session id: one actor's device details must never reach another
    actor's screen.

    Presence rendered here is NOT the boundary. The approval transaction re-reads
    it and refuses an unacknowledged eviction of a doctor who came online after
    this page was drawn.
--}}
@php
    $online = (bool) ($presence['online'] ?? false);
    $linked = (bool) ($presence['linked'] ?? true);
    $branch = $presence['branch'] ?? null;
    $room = $presence['room'] ?? null;
@endphp

<div class="space-y-1">
    @if ($online)
        <x-ui.badge tone="danger">SEDANG ONLINE</x-ui.badge>
        <span class="block text-xs text-ink-soft">
            {{ $branch ?? 'Cabang tidak diketahui' }} &middot; {{ $room ?? 'Ruangan tidak diketahui' }}
        </span>
    @else
        <x-ui.badge tone="neutral">Offline</x-ui.badge>
        @unless ($linked)
            <span class="block text-xs text-ink-muted">Akun dokter belum terhubung</span>
        @endunless
    @endif
</div>
