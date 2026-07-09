<x-settings-shell title="Monitoring Fondasi">
    @php
        $report = $report ?? [];
        $decision = $report['decision'] ?? 'UNKNOWN';
        $summary = $report['summary'] ?? [];
        $signals = $report['signals'] ?? [];
        $reasons = $report['reasons'] ?? [];

        $byKey = collect($signals)->keyBy('key');
        $runtime = ($byKey['app_runtime']['details'] ?? []);

        $decisionTone = [
            'GO' => ['success', 'bg-success-50 border-success text-success-700'],
            'WATCH' => ['warning', 'bg-warning-50 border-warning text-warning-700'],
            'FAIL' => ['danger', 'bg-danger-50 border-danger text-danger-700'],
            'UNKNOWN' => ['info', 'bg-navy-50 border-hairline text-ink-soft'],
        ];
        $statusTone = fn ($s) => match ($s) {
            'GO' => 'success',
            'WATCH' => 'warning',
            'FAIL' => 'danger',
            default => 'info',
        };
        $banner = $decisionTone[$decision] ?? $decisionTone['UNKNOWN'];
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">MON-1 — Foundation Track</p>
            <h2 class="mt-1 text-xl font-semibold text-navy">Monitoring &amp; Observability Fondasi</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Konsolidasi read-only dari signal yang sudah ada (health, deploy/backup, queue, storage/cache, log, audit).
                Halaman ini tidak menjalankan audit berat, tidak mengubah state runtime, dan memasking semua data sensitif.
            </p>
            @if (Route::has('foundation.rollout.five-branch-readiness'))
                <a href="{{ route('foundation.rollout.five-branch-readiness') }}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-800">
                    → Kesiapan Rollout 5 Cabang (ROLL-5-1): keputusan bertahap 1 → 3 → 5 cabang
                </a>
            @endif
        </div>

        {{-- Overall decision banner --}}
        <div class="rounded-xl border-l-4 p-5 {{ $banner[1] }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide opacity-80">Keputusan Konsolidasi</p>
                    <p class="mt-1 text-2xl font-bold">{{ $decision }}</p>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <x-ui.badge tone="success">GO {{ $summary['GO'] ?? 0 }}</x-ui.badge>
                    <x-ui.badge tone="warning">WATCH {{ $summary['WATCH'] ?? 0 }}</x-ui.badge>
                    <x-ui.badge tone="danger">FAIL {{ $summary['FAIL'] ?? 0 }}</x-ui.badge>
                    <x-ui.badge tone="info">UNKNOWN {{ $summary['UNKNOWN'] ?? 0 }}</x-ui.badge>
                </div>
            </div>
            @if (! empty($reasons))
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-xs opacity-70">
                Dihasilkan: {{ $report['generated_at'] ?? '—' }} · Audit berat tidak dijalankan di halaman (gunakan CLI
                <code>php artisan foundation:monitoring-observability-check --include-audits --strict</code>).
            </p>
        </div>

        {{-- App runtime --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-navy">Runtime Aplikasi</h3>
                <x-ui.badge :tone="$statusTone($byKey['app_runtime']['status'] ?? 'UNKNOWN')">{{ $byKey['app_runtime']['status'] ?? 'UNKNOWN' }}</x-ui.badge>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-muted">Environment</dt><dd class="font-medium text-ink">{{ $runtime['environment'] ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Debug</dt><dd class="font-medium text-ink">{{ ($runtime['debug'] ?? false) ? 'ON' : 'off' }}</dd></div>
                <div><dt class="text-ink-muted">Maintenance</dt><dd class="font-medium text-ink">{{ ($runtime['maintenance'] ?? false) ? 'ON' : 'off' }}</dd></div>
                <div><dt class="text-ink-muted">Commit</dt><dd class="font-medium text-ink">{{ $runtime['commit'] ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Tag</dt><dd class="font-medium text-ink">{{ $runtime['tag'] ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">PHP / Laravel</dt><dd class="font-medium text-ink">{{ $runtime['php_version'] ?? '—' }} / {{ $runtime['laravel_version'] ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        {{-- Signal table --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold text-navy">Signal Observability</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead>
                        <tr class="text-left text-ink-soft">
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Signal</th>
                            <th class="px-3 py-2 font-medium">Ringkasan</th>
                            <th class="px-3 py-2 font-medium">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($signals as $signal)
                            <tr>
                                <td class="px-3 py-2 align-top">
                                    <x-ui.badge :tone="$statusTone($signal['status'] ?? 'UNKNOWN')">{{ $signal['status'] ?? 'UNKNOWN' }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 align-top font-medium text-ink">{{ $signal['label'] ?? $signal['key'] ?? '—' }}</td>
                                <td class="px-3 py-2 align-top text-ink-soft">{{ $signal['summary'] ?? '' }}</td>
                                <td class="px-3 py-2 align-top text-ink-muted">{{ $signal['remediation'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-ink-muted">
                Status audit di atas berasal dari evidence tercache; untuk audit penuh jalankan perintah CLI-nya.
                UI ini read-only: tidak ada retry/hapus queue, tidak ada penghapusan log.
            </p>
        </x-ui.card>

        {{-- Runbook links --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold text-navy">Runbook &amp; Referensi</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-700">
                <li><code>docs/runbooks/mon-1-foundation-monitoring-observability-runbook.md</code></li>
                <li><code>docs/sprints/mon-1-foundation-monitoring-observability-gap-consolidation.md</code></li>
                <li>Penuh: <code>php artisan foundation:monitoring-observability-check --strict --json</code></li>
                @if (Route::has('developer-console.index'))
                    <li><a class="hover:underline" href="{{ route('developer-console.index') }}">← Developer Console (ENT-7)</a></li>
                @endif
            </ul>
        </x-ui.card>
    </div>
</x-settings-shell>
