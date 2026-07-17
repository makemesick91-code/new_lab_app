{{-- SATUSEHAT-4C — Branch readiness detail + internal pilot controls.
     Credential-independent, NIK/raw notes never rendered. --}}
<x-settings-shell title="SATUSEHAT — Detail Kesiapan Cabang">
    <x-ui.page-header
        title="Kesiapan: {{ $branch?->code }} — {{ $branch?->name }}"
        subtitle="Kesiapan internal & kontrol pilot. Kesiapan eksternal tetap terblokir (SATUSEHAT-2 WATCH).">
        <x-slot:breadcrumb><a href="{{ route('satusehat.branches.index') }}">Kesiapan Cabang</a> · Detail</x-slot:breadcrumb>
        @if ($canRemediate)
            <form method="POST" action="{{ route('satusehat.branches.recalculate', $branch?->id) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Hitung Ulang Kesiapan</x-ui.button>
            </form>
        @endif
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach ($errors->all() as $msg)<x-ui.alert variant="danger">{{ $msg }}</x-ui.alert>@endforeach

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.kpi-card label="Skor Kesiapan Internal" :value="$snapshot['score']['score'] ?? 'N/A'" />
        <x-ui.kpi-card label="Keputusan" :value="$eligibility['decision']" />
        <x-ui.kpi-card label="Isu Keras Terbuka" :value="$snapshot['open_hard_issues']" />
        <x-ui.kpi-card label="Blocker Eksternal" value="BLOCKED" />
    </div>

    @if (($snapshot['score']['capped'] ?? false))
        <x-ui.alert variant="warning">Skor dibatasi oleh keberadaan isu keras (hard) — selesaikan remediasi terlebih dahulu.</x-ui.alert>
    @endif

    {{-- Component rates --}}
    <x-ui.card class="mt-4" title="Rincian Kesiapan (Rate per Komponen)">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5 text-sm">
            @foreach ($snapshot['rates'] as $key => $rate)
                <div class="rounded-lg border border-hairline p-3">
                    <div class="text-ink-muted">{{ $key }}</div>
                    <div class="text-lg font-semibold">{{ $rate !== null ? number_format((float) $rate, 1).'%' : 'N/A' }}</div>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Eligibility gates --}}
    <x-ui.card class="mt-4" title="Gate Kelayakan Pilot Internal">
        <x-ui.table>
            <x-slot:head><tr><th class="text-left">Gate</th><th class="text-left">Kategori</th><th class="text-left">Status</th><th class="text-left">Keterangan</th></tr></x-slot:head>
            @foreach ($eligibility['gates'] as $gate)
                <tr>
                    <td>{{ $gate['label'] }}</td>
                    <td><x-ui.badge tone="{{ $gate['category'] === 'internal' ? 'info' : 'neutral' }}">{{ $gate['category'] }}</x-ui.badge></td>
                    <td><x-ui.badge tone="{{ $gate['passed'] ? 'success' : 'danger' }}">{{ $gate['passed'] ? 'LULUS' : 'BELUM' }}</x-ui.badge></td>
                    <td class="text-ink-soft">{{ $gate['detail'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{-- Pilot controls --}}
    @if ($canConfigure || $canApprove || $canRehearse)
        <x-ui.card class="mt-4" title="Kontrol Pilot Internal">
            <div class="flex flex-wrap gap-2">
                @if ($canConfigure && ! ($profile->isSuspended() ?? false))
                    <form method="POST" action="{{ route('satusehat.branches.pilot.select', $branch?->id) }}">@csrf
                        <x-ui.button type="submit" variant="secondary">Jadikan Kandidat</x-ui.button></form>
                @endif
                @if ($canApprove)
                    <form method="POST" action="{{ route('satusehat.branches.pilot.approve', $branch?->id) }}">@csrf
                        <x-ui.button type="submit" variant="primary">Setujui Pilot (INTERNAL GO)</x-ui.button></form>
                @endif
                @if ($canRehearse)
                    <form method="POST" action="{{ route('satusehat.branches.pilot.rehearse', $branch?->id) }}">@csrf
                        <input type="hidden" name="dry_run" value="0">
                        <x-ui.button type="submit" variant="secondary">Jalankan Rehearsal (Sintetis)</x-ui.button></form>
                @endif
                @if ($canConfigure && ! ($profile->isSuspended() ?? false))
                    <form method="POST" action="{{ route('satusehat.branches.pilot.suspend', $branch?->id) }}" class="flex gap-2">@csrf
                        <x-ui.input name="reason" placeholder="Alasan penangguhan (min 10)" />
                        <x-ui.button type="submit" variant="danger">Tangguhkan</x-ui.button></form>
                @endif
                @if ($canConfigure && ($profile->isSuspended() ?? false))
                    <form method="POST" action="{{ route('satusehat.branches.pilot.resume', $branch?->id) }}">@csrf
                        <x-ui.button type="submit" variant="secondary">Lanjutkan Pilot</x-ui.button></form>
                @endif
            </div>
            <p class="mt-2 text-xs text-ink-muted">Persetujuan pilot bersifat INTERNAL. Tidak mengaktifkan pengiriman eksternal maupun produksi.</p>
        </x-ui.card>
    @endif

    {{-- Open issues (PII-free) --}}
    <x-ui.card class="mt-4" title="Isu Kualitas Data Terbuka">
        <x-ui.table>
            <x-slot:head><tr><th class="text-left">Aturan</th><th class="text-left">Severity</th><th class="text-left">Prioritas</th><th class="text-left">Status</th><th class="text-left">SLA</th></tr></x-slot:head>
            @forelse ($issues as $issue)
                <tr>
                    <td>{{ $issue->rule_code }}</td>
                    <td><x-ui.badge tone="{{ $issue->severityTone() }}">{{ $issue->severity }}</x-ui.badge></td>
                    <td><x-ui.badge tone="{{ $issue->priorityTone() }}">{{ $issue->priorityLabel() }}</x-ui.badge></td>
                    <td>{{ $issue->statusLabel() }}</td>
                    <td>{{ $issue->due_at ? $issue->due_at->format('d/m H:i').($issue->isOverdue() ? ' (lewat)' : '') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5"><x-ui.empty-state title="Tidak ada isu terbuka." /></td></tr>
            @endforelse
        </x-ui.table>
        @if (method_exists($issues, 'links')){{ $issues->links() }}@endif
    </x-ui.card>
</x-settings-shell>
