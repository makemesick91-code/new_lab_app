@php($mask = app(\App\Modules\Patient\Services\PatientDataCompletenessService::class))
<x-settings-shell title="Detail Kandidat SATUSEHAT">
    <x-ui.page-header
        title="Kandidat #{{ $candidate->id }}"
        subtitle="Kunjungan {{ $candidate->clinicVisit?->visit_number }} — {{ optional($candidate->clinicVisit?->visit_date)->format('d M Y') }}">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('satusehat.submissions.index')">Kembali</x-ui.button>
            <x-ui.button variant="secondary" :href="route('satusehat.submissions.preview', $candidate)">Preview FHIR (lokal)</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Pasien &amp; Kunjungan">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-ink-soft">Pasien</dt><dd class="text-ink">{{ $candidate->patient?->name }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-soft">No. RM</dt><dd class="text-ink">{{ $candidate->patient?->medical_record_number }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-soft">NIK</dt><dd class="text-ink">{{ $mask->maskKtp($candidate->patient?->ktp_number) ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-soft">Dokter</dt><dd class="text-ink">{{ $candidate->doctor?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-soft">Cabang</dt><dd class="text-ink">{{ $candidate->branch?->name }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-soft">Lingkungan</dt><dd class="text-ink">{{ $candidate->environment }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Status">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$candidate->readinessTone()">Readiness: {{ $candidate->readinessLabel() }}</x-ui.badge>
                <x-ui.badge :tone="$candidate->reviewTone()">Review: {{ $candidate->reviewLabel() }}</x-ui.badge>
            </div>

            @if ($candidate->isSourceChanged())
                <x-ui.alert variant="warning" class="mt-3">
                    Sumber klinis berubah setelah persetujuan. Persetujuan sebelumnya dicabut — review ulang diperlukan.
                </x-ui.alert>
            @endif

            <h3 class="mt-4 text-sm font-semibold text-ink">Alasan Readiness</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse ($candidate->readiness_reasons ?? [] as $reason)
                    <li class="flex items-start gap-2">
                        <x-ui.badge :tone="$reason['severity'] === 'blocked' ? 'danger' : ($reason['severity'] === 'incomplete' ? 'warning' : 'neutral')">{{ $reason['severity'] }}</x-ui.badge>
                        <span class="text-ink-soft">{{ $reason['message'] }}</span>
                    </li>
                @empty
                    <li class="text-ink-muted">Tidak ada catatan readiness.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>

    @canany(['review_satusehat_submissions', 'send_satusehat_submissions'])
        <x-ui.card title="Tindakan Review" class="mt-4">
            <div class="flex flex-wrap items-start gap-3">
                <form method="POST" action="{{ route('satusehat.submissions.refresh', $candidate) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Hitung Ulang Readiness</x-ui.button>
                </form>

                @can('review_satusehat_submissions')
                    <form method="POST" action="{{ route('satusehat.submissions.approve', $candidate) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success" :disabled="! $candidate->canApprove()">Setujui</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('satusehat.submissions.exclude', $candidate) }}" class="flex items-end gap-2">
                        @csrf
                        <x-ui.input label="Alasan pengecualian" name="exclusion_reason" required />
                        <x-ui.button type="submit" variant="warning">Kecualikan</x-ui.button>
                    </form>
                @endcan
            </div>
        </x-ui.card>
    @endcanany

    <x-ui.card title="Riwayat Audit" class="mt-4" padding="">
        <div class="overflow-x-auto">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr class="text-left text-ink-soft">
                        <th class="px-4 py-2">Waktu</th>
                        <th class="px-4 py-2">Peristiwa</th>
                        <th class="px-4 py-2">Aktor</th>
                        <th class="px-4 py-2">Ringkasan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($timeline as $log)
                        <tr>
                            <td class="px-4 py-2 text-sm">{{ optional($log->created_at)->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2 text-sm">{{ $log->event }}</td>
                            <td class="px-4 py-2 text-sm">{{ $log->actor?->name ?? 'sistem' }}</td>
                            <td class="px-4 py-2 text-sm text-ink-soft">{{ $log->summary }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-ink-muted">Belum ada audit.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </div>
    </x-ui.card>
</x-settings-shell>
