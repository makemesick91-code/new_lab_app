{{-- SATUSEHAT-4A — Issue detail + remediation actions + audit timeline.
     Hard issues can never be waived; resolve = server revalidation. --}}
<x-settings-shell title="SATUSEHAT — Detail Isu">
    <x-ui.page-header
        title="Isu: {{ $issue->rule_code }}"
        subtitle="{{ $issue->message }}">
        <x-slot:breadcrumb>SATUSEHAT / Isu Kualitas Data</x-slot:breadcrumb>
        <x-ui.button variant="ghost" :href="route('satusehat.readiness.issues')">← Daftar Isu</x-ui.button>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Aksi ditolak">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-ui.card title="Ringkasan" class="lg:col-span-2">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-ink-muted">Severity</dt><dd><x-ui.badge :tone="$issue->severityTone()">{{ $issue->severity }}</x-ui.badge></dd></div>
                <div><dt class="text-ink-muted">Status</dt><dd><x-ui.badge :tone="$issue->statusTone()">{{ $issue->statusLabel() }}</x-ui.badge></dd></div>
                <div><dt class="text-ink-muted">Pasien</dt><dd>{{ $issue->patient?->name ?? '—' }} <span class="text-xs text-ink-muted">(RM: {{ $issue->patient?->medical_record_number ?? '—' }})</span></dd></div>
                <div><dt class="text-ink-muted">Kunjungan</dt><dd>{{ $issue->clinicVisit?->visit_number ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Dokter</dt><dd>{{ $issue->doctor?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Pemilik Perbaikan</dt><dd>{{ $issue->owner_role ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Ditugaskan ke</dt><dd>{{ $issue->assignedTo?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Field</dt><dd>{{ $issue->entity_type }}.{{ $issue->field_path ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Pertama terdeteksi</dt><dd>{{ $issue->first_detected_at?->format('d M Y H:i') }}</dd></div>
                <div><dt class="text-ink-muted">Terakhir terdeteksi</dt><dd>{{ $issue->last_detected_at?->format('d M Y H:i') }}</dd></div>
            </dl>

            @if ($issue->remediation_action)
                <x-ui.alert variant="info" title="Tindakan Perbaikan" class="mt-3">{{ $issue->remediation_action }}</x-ui.alert>
            @endif
            @if ($issue->status === \App\Modules\Satusehat\Models\SatusehatDataQualityIssue::STATUS_WAIVED)
                <x-ui.alert variant="warning" title="Waiver aktif" class="mt-3">
                    Alasan: {{ $issue->waiver_reason }} — waiver hanya menutup triase; readiness kanonis tidak berubah.
                </x-ui.alert>
            @endif

            {{-- Quick navigation to the remediation surfaces --}}
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($issue->clinic_visit_id)
                    <x-ui.button size="sm" variant="secondary" :href="route('rme.visits.show', $issue->clinic_visit_id)">Buka Kunjungan</x-ui.button>
                @endif
                @if ($issue->satusehat_candidate_id)
                    <x-ui.button size="sm" variant="secondary" :href="route('satusehat.submissions.show', $issue->satusehat_candidate_id)">Buka Kandidat</x-ui.button>
                @endif
                @can('manage_satusehat_mappings')
                    <x-ui.button size="sm" variant="ghost" :href="route('satusehat.mappings.index')">Mapping Kode</x-ui.button>
                @endcan
            </div>
        </x-ui.card>

        <x-ui.card title="Aksi Remediasi">
            @can('manage', $issue)
                <div class="space-y-2">
                    <form method="POST" action="{{ route('satusehat.readiness.issues.acknowledge', $issue->id) }}">@csrf
                        <x-ui.button size="sm" type="submit" variant="secondary" class="w-full">Akui Isu</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('satusehat.readiness.issues.start', $issue->id) }}">@csrf
                        <x-ui.button size="sm" type="submit" variant="secondary" class="w-full">Mulai Perbaikan</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('satusehat.readiness.issues.request-review', $issue->id) }}">@csrf
                        <x-ui.button size="sm" type="submit" variant="secondary" class="w-full">Minta Review Klinis</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('satusehat.readiness.issues.resolve', $issue->id) }}">@csrf
                        <x-ui.button size="sm" type="submit" variant="success" class="w-full">Validasi Selesai</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('satusehat.readiness.issues.reopen', $issue->id) }}">@csrf
                        <x-ui.button size="sm" type="submit" variant="warning" class="w-full">Buka Kembali</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('satusehat.readiness.issues.assign', $issue->id) }}" class="border-t border-hairline pt-2">
                        @csrf
                        <x-ui.input label="Tugaskan ke (User ID)" name="assigned_to" type="number" />
                        <x-ui.button size="sm" type="submit" variant="secondary" class="mt-2 w-full">Tugaskan</x-ui.button>
                    </form>
                </div>
            @else
                <p class="text-sm text-ink-muted">Anda hanya memiliki akses baca pada isu ini.</p>
            @endcan

            @can('waive', $issue)
                @unless ($issue->isHard())
                    <form method="POST" action="{{ route('satusehat.readiness.issues.waive', $issue->id) }}" class="mt-3 border-t border-hairline pt-3">
                        @csrf
                        <x-ui.textarea label="Alasan waiver (wajib)" name="reason" rows="2" />
                        <x-ui.input label="Kedaluwarsa (opsional)" name="waiver_expires_at" type="date" />
                        <x-ui.button size="sm" type="submit" variant="warning" class="mt-2 w-full">Kecualikan (Waiver)</x-ui.button>
                    </form>
                @else
                    <x-ui.alert variant="danger" class="mt-3">Isu keras (hard) tidak dapat dikecualikan.</x-ui.alert>
                @endunless
            @endcan
        </x-ui.card>
    </div>

    <x-ui.card title="Linimasa Audit" class="mt-4" description="Append-only — tidak dapat diubah.">
        @forelse ($timeline as $log)
            <div class="border-b border-hairline py-2 text-sm">
                <span class="font-medium">{{ $log->event }}</span>
                <span class="text-xs text-ink-muted">— {{ $log->created_at?->format('d M Y H:i:s') }}</span>
                @if ($log->summary)
                    <div class="text-xs text-ink-soft">{{ $log->summary }}</div>
                @endif
            </div>
        @empty
            <p class="text-sm text-ink-muted">Belum ada jejak audit.</p>
        @endforelse
    </x-ui.card>
</x-settings-shell>
