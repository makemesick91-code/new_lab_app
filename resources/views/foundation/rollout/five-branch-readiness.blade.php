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

        $restore = collect($signals)->firstWhere('key', 'restore_drill_evidence') ?? [];
        $restoreDetails = $restore['details'] ?? [];
        $stage1 = collect($stages)->firstWhere('key', 'stage_1') ?? [];
        $laterStages = collect($stages)->filter(fn ($s) => ($s['key'] ?? null) !== 'stage_1' && ($s['status'] ?? 'GO') !== 'GO');
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

        {{-- Stage-1 GO clearance --}}
        <x-ui.card title="Kelayakan Stage-1 (independen dari Stage-3)">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.badge :tone="$statusTone($stage1['status'] ?? 'UNKNOWN')">Stage-1: {{ $stage1['status'] ?? '—' }}</x-ui.badge>
                <span class="text-sm text-ink-soft">
                    Kesiapan dasar: <strong>{{ $stage1['base_status'] ?? '—' }}</strong>
                    &middot; Cabang: <strong>{{ $stage1['branch_status'] ?? '—' }}</strong>
                    ({{ $stage1['available_branches'] ?? '—' }}/{{ $stage1['branch_target'] ?? 1 }} cabang RME)
                </span>
            </div>
            <p class="mt-2 text-xs text-ink-muted">
                Stage-1 dapat GO tanpa menunggu target 5 cabang. Setelah bukti uji restore GO dan kategori dasar GO
                dengan minimal 1 cabang RME aktif, Stage-1 layak dinaikkan sementara Stage-3 tetap WATCH sampai 5 cabang aktif.
            </p>
            @if ($laterStages->isNotEmpty())
                <p class="mt-2 text-xs text-warning-700">
                    Stage lanjutan masih WATCH:
                    {{ $laterStages->map(fn ($s) => ($s['label'] ?? $s['key'] ?? '?').' ('.($s['status'] ?? '?').')')->implode(', ') }}
                    — menunggu jumlah cabang RME aktif memenuhi target.
                </p>
            @endif
        </x-ui.card>

        {{-- Restore-drill evidence --}}
        <x-ui.card title="Bukti Uji Restore (Staging/Disposable)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-ui.badge :tone="$statusTone($restore['status'] ?? 'UNKNOWN')">{{ $restore['status'] ?? '—' }}</x-ui.badge>
                <span class="text-xs text-ink-muted">Uji restore hanya ke DB staging/disposable — tidak pernah menimpa produksi.</span>
            </div>
            <p class="mt-2 text-sm text-ink-soft">{{ $restore['summary'] ?? 'Tidak ada bukti.' }}</p>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-xs text-ink-soft sm:grid-cols-2">
                <div class="flex justify-between gap-2"><dt class="text-ink-muted">Bukti tersedia</dt><dd>{{ ($restoreDetails['evidence_present'] ?? false) ? 'Ya' : 'Belum' }}</dd></div>
                @if (! empty($restoreDetails['evidence_file']))
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">File</dt><dd>{{ $restoreDetails['evidence_file'] }}</dd></div>
                @endif
                @if (array_key_exists('environment', $restoreDetails))
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Environment</dt><dd>{{ $restoreDetails['environment'] ?: '—' }}</dd></div>
                @endif
                @if (array_key_exists('restore_target', $restoreDetails))
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Target restore</dt><dd>{{ $restoreDetails['restore_target'] ?: '—' }}</dd></div>
                @endif
                @if (array_key_exists('source_backup_file', $restoreDetails))
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Backup sumber</dt><dd>{{ $restoreDetails['source_backup_file'] ?: '—' }}</dd></div>
                @endif
                @if (array_key_exists('production_overwrite', $restoreDetails))
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Overwrite produksi</dt><dd>{{ ($restoreDetails['production_overwrite'] ?? true) ? 'YA (TIDAK AMAN)' : 'false (aman)' }}</dd></div>
                @endif
                @if (array_key_exists('age_hours', $restoreDetails) && $restoreDetails['age_hours'] !== null)
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Usia bukti</dt><dd>{{ $restoreDetails['age_hours'] }} jam</dd></div>
                @endif
            </dl>
            @if (! empty($restoreDetails['verification']))
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($restoreDetails['verification'] as $vk => $vv)
                        <x-ui.badge :tone="$statusTone($vv)">{{ $vk }}: {{ $vv }}</x-ui.badge>
                    @endforeach
                </div>
            @endif
            @if (! empty($restore['remediation']))
                <p class="mt-3 text-xs text-warning-700">{{ $restore['remediation'] }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-3 text-xs text-ink-muted">
                <span>Validasi: <code>php artisan rollout:restore-drill-evidence --strict</code></span>
                <span>Runbook: <code>docs/runbooks/roll-5-backup-restore-drill-runbook.md</code></span>
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
