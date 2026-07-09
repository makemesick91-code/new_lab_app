<x-settings-shell title="Kesiapan Rollout 5 Cabang">
    @php
        $report = $report ?? [];
        $decision = $report['decision'] ?? 'UNKNOWN';
        $summary = $report['summary'] ?? [];
        $signals = $report['signals'] ?? [];
        $reasons = $report['reasons'] ?? [];
        $stages = $report['stages'] ?? [];

        $decisionTone = [
            'GO' => 'bg-success-50 border-success text-success-700',
            'WATCH' => 'bg-warning-50 border-warning text-warning-700',
            'FAIL' => 'bg-danger-50 border-danger text-danger-700',
            'UNKNOWN' => 'bg-navy-50 border-hairline text-ink-soft',
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
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">ROLL-5-1 — Controlled Rollout</p>
            <h2 class="mt-1 text-xl font-semibold text-navy">Kesiapan Rollout Terkendali 5 Cabang</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Konsolidasi read-only kesiapan rollout bertahap (Stage 1: 1 cabang &middot; Stage 2: 3 cabang &middot; Stage 3: 5 cabang).
                Halaman ini menggunakan kembali MON-1, health ENT-8, dan audit yang sudah ada; tidak menjalankan audit berat/uji kapasitas,
                tidak mengubah state runtime, dan memasking semua data sensitif. Sertifikasi ini hanya untuk rollout terkendali 5 cabang —
                bukan skala nasional, HA cluster, atau sertifikasi DR penuh.
            </p>
        </div>

        {{-- Overall decision banner --}}
        <div class="rounded-xl border-l-4 p-5 {{ $banner }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide opacity-80">Keputusan Kesiapan</p>
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
                Dihasilkan: {{ $report['generated_at'] ?? '—' }} &middot; Monitoring (MON-1): {{ $report['monitoring_decision'] ?? '—' }}
                &middot; Audit berat/uji kapasitas dijalankan lewat CLI
                <code>php artisan rollout:five-branch-readiness --include-audits --capacity-smoke</code>.
            </p>
        </div>

        {{-- Rollout stages --}}
        <x-ui.card title="Tahapan Rollout (1 → 3 → 5 cabang)">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ($stages as $stage)
                    <div class="rounded-xl border border-hairline p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-navy">{{ $stage['label'] ?? '—' }}</p>
                            <x-ui.badge :tone="$statusTone($stage['status'] ?? 'UNKNOWN')">{{ $stage['status'] ?? '—' }}</x-ui.badge>
                        </div>
                        <p class="mt-2 text-xs text-ink-muted">
                            Butuh {{ $stage['branch_target'] ?? '—' }} cabang &middot;
                            tersedia {{ $stage['available_branches'] ?? '—' }} cabang RME aktif
                        </p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Category signal table --}}
        <x-ui.card title="Kategori Kesiapan">
            <x-ui.table>
                <thead class="bg-navy-50 text-ink">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">Status</th>
                        <th class="px-3 py-2 text-left font-semibold">Signal</th>
                        <th class="px-3 py-2 text-left font-semibold">Ringkasan</th>
                        <th class="px-3 py-2 text-left font-semibold">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($signals as $signal)
                        <tr class="align-top">
                            <td class="px-3 py-2">
                                <x-ui.badge :tone="$statusTone($signal['status'] ?? 'UNKNOWN')">{{ $signal['status'] ?? '—' }}</x-ui.badge>
                            </td>
                            <td class="px-3 py-2 text-sm font-medium text-navy">{{ $signal['label'] ?? $signal['key'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-ink-soft">{{ $signal['summary'] ?? '' }}</td>
                            <td class="px-3 py-2 text-xs text-ink-muted">{{ $signal['remediation'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>

        {{-- Operator commands + runbook links --}}
        <x-ui.card title="Perintah & Runbook Rollout">
            <p class="text-sm text-ink-soft">Jalankan sebelum menaikkan setiap stage rollout:</p>
            <ul class="mt-2 space-y-1 text-xs">
                @foreach (($requiredCommands ?? []) as $command)
                    <li><code>php artisan {{ $command }}</code></li>
                @endforeach
                <li><code>php artisan rollout:five-branch-readiness --include-audits --strict --stage=1</code></li>
            </ul>
            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                @if (Route::has('foundation.monitoring.index'))
                    <a href="{{ route('foundation.monitoring.index') }}" class="font-medium text-brand-600 hover:text-brand-800">→ Monitoring Fondasi (MON-1)</a>
                @endif
                <span class="text-ink-muted">Runbook: <code>docs/runbooks/roll-5-controlled-rollout-runbook.md</code></span>
                <span class="text-ink-muted">Uji restore: <code>docs/runbooks/roll-5-backup-restore-drill-runbook.md</code></span>
            </div>
        </x-ui.card>
    </div>
</x-settings-shell>
