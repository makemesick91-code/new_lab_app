{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — merge case detail.

     PRESENTATION ONLY. Comparison statuses, final values, counts, blockers and
     risk flags are all computed by PatientMergeCaseService::preview(). Sensitive
     fields (NIK/KTP, phone) arrive masked; there is no way for this template to
     print a full value because it never receives one.

     The canonical patient and the final identity are chosen SEPARATELY: the
     surviving row can be Patient B while the final name comes from Patient A. --}}
@php
    use App\Modules\PatientMerge\Support\PatientMergeField;
    use App\Modules\PatientMerge\Support\PatientMergeStatus;
    use App\Modules\PatientMerge\Services\PatientMergeReversalService;

    $a = $case->patientA;
    $b = $case->patientB;
    $canEdit = auth()->user()->can('update', $case);
    $riskLabels = [
        'ktp_conflict' => 'Konflik NIK/KTP',
        'dob_conflict' => 'Konflik tanggal lahir',
        'gender_conflict' => 'Konflik jenis kelamin',
        'both_have_rme' => 'Kedua pasien memiliki RME',
        'both_have_odontogram' => 'Kedua pasien memiliki odontogram',
        'both_have_receivables' => 'Kedua pasien memiliki piutang aktif',
        'legacy_native_mix' => 'Arsip legacy & data native bercampur',
        'cross_branch' => 'Lintas cabang',
    ];
    $blockers = $preview['blockers'];
@endphp

<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header :title="'Pengajuan '.$case->case_number" :subtitle="'Diajukan oleh '.($case->requester?->name ?? '—').' pada '.$case->requested_at?->format('d-m-Y H:i')">
            <x-slot:breadcrumb>Duplikasi Pasien / Pengajuan Merge / {{ $case->case_number }}</x-slot:breadcrumb>
            <x-slot:actions>@include('patient-merge.partials.status-badge', ['status' => $case->status])</x-slot:actions>
        </x-ui.page-header>

        @include('patient-merge.partials.nav')

        <x-ui.card title="Alasan pengajuan">
            <p class="whitespace-pre-line text-sm text-ink">{{ $case->request_reason }}</p>
            @if ($case->review_note)
                <p class="mt-3 text-sm text-ink-soft"><span class="font-semibold">Catatan peninjau ({{ $case->reviewer?->name }}):</span> {{ $case->review_note }}</p>
            @endif
            @if ($case->cancel_reason)
                <p class="mt-3 text-sm text-ink-soft"><span class="font-semibold">Alasan pembatalan:</span> {{ $case->cancel_reason }}</p>
            @endif
        </x-ui.card>

        @if ($preview['risk_flags'] !== [])
            <x-ui.alert variant="warning" title="Perhatian: kasus berisiko tinggi">
                <ul class="list-inside list-disc">
                    @foreach ($preview['risk_flags'] as $flag)<li>{{ $riskLabels[$flag] ?? $flag }}</li>@endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($case->isOpen() && $blockers !== [])
            <x-ui.alert variant="danger" title="Penggabungan belum dapat dilanjutkan" data-testid="merge-blockers">
                <ul class="list-inside list-disc">
                    @foreach ($blockers as $blocker)<li>{{ $blocker['message'] }}</li>@endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- Identity reconciliation --}}
        <x-ui.card title="Rekonsiliasi identitas" description="Pilih sumber nilai final per kolom: Pasien A, Pasien B, atau nilai manual yang telah diverifikasi (wajib alasan). Kolom bertanda * adalah identitas utama dan konfliknya harus diselesaikan." padding="p-0">
            @if ($canEdit)<form method="POST" action="{{ route('patient-merge.cases.resolve', $case) }}">@csrf @method('PUT')@endif
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Kolom</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien A<br><span class="normal-case font-normal">{{ $a?->medical_record_number }}</span></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien B<br><span class="normal-case font-normal">{{ $b?->medical_record_number }}</span></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Nilai final</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline bg-surface">
                    @foreach ($preview['identity'] as $field => $row)
                        <tr class="{{ ! $row['resolved'] ? 'bg-warning-50' : '' }}">
                            <td class="px-4 py-3 align-top text-sm font-medium text-navy">{{ $row['label'] }}@if ($row['critical'])<span class="text-danger"> *</span>@endif</td>
                            <td class="px-4 py-3 align-top text-sm text-ink">{{ $row['a_display'] ?? '—' }}</td>
                            <td class="px-4 py-3 align-top text-sm text-ink">{{ $row['b_display'] ?? '—' }}</td>
                            <td class="px-4 py-3 align-top text-sm">@include('patient-merge.partials.identity-status', ['status' => $row['status']])</td>
                            <td class="px-4 py-3 align-top text-sm">
                                @if ($canEdit)
                                    <div class="space-y-2" x-data="{ source: @js(old("fields.$field.source", $row['source'])) }">
                                        <select name="fields[{{ $field }}][source]" x-model="source" class="block w-full rounded-lg border-hairline text-sm" aria-label="Sumber {{ $row['label'] }}">
                                            <option value="">— Pilih sumber —</option>
                                            @if ($row['status'] === 'match')<option value="matched">Sama (A = B)</option>@endif
                                            @if ($row['a_present'])<option value="patient_a">Pasien A</option>@endif
                                            @if ($row['b_present'])<option value="patient_b">Pasien B</option>@endif
                                            <option value="manual">Nilai manual terverifikasi</option>
                                            @if (! $row['critical'] || (! $row['a_present'] && ! $row['b_present']))<option value="empty">Kosongkan</option>@endif
                                        </select>
                                        <div x-show="source === 'manual'" x-cloak class="space-y-2">
                                            @if ($field === 'gender')
                                                <select name="fields[{{ $field }}][manual_value]" class="block w-full rounded-lg border-hairline text-sm" aria-label="Nilai manual {{ $row['label'] }}">
                                                    @foreach (PatientMergeField::GENDERS as $gender)<option value="{{ $gender }}">{{ \App\Modules\PatientMerge\Support\PatientIdentityMask::field('gender', $gender) }}</option>@endforeach
                                                </select>
                                            @else
                                                <input type="{{ $field === 'date_of_birth' ? 'date' : 'text' }}" name="fields[{{ $field }}][manual_value]" autocomplete="off"
                                                       class="block w-full rounded-lg border-hairline text-sm" placeholder="Nilai terverifikasi" aria-label="Nilai manual {{ $row['label'] }}">
                                            @endif
                                            <input type="text" name="fields[{{ $field }}][reason]" class="block w-full rounded-lg border-hairline text-sm"
                                                   placeholder="Alasan / dokumen verifikasi (min. 10 karakter)" aria-label="Alasan nilai manual {{ $row['label'] }}">
                                        </div>
                                        @if ($row['resolved'])
                                            <p class="text-xs text-ink-muted">Saat ini: {{ $row['final_display'] ?? '(kosong)' }} ← {{ $row['source_label'] }}</p>
                                        @endif
                                        @error("fields.$field")<p class="text-xs text-danger">{{ $message }}</p>@enderror
                                    </div>
                                @else
                                    @if ($row['resolved'])
                                        <div class="text-navy">{{ $row['final_display'] ?? '(kosong)' }}</div>
                                        <div class="text-xs text-ink-muted">← {{ $row['source_label'] }}</div>
                                        @if ($row['manual_reason'])<div class="text-xs text-ink-muted">Alasan: {{ $row['manual_reason'] }}</div>@endif
                                    @else
                                        <x-ui.badge tone="warning">Belum diselesaikan</x-ui.badge>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>

            <div class="space-y-4 border-t border-hairline p-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-navy">Pasien canonical (data aktif setelah penggabungan)</legend>
                    <p class="text-xs text-ink-soft">Pilihan ini menentukan baris pasien dan Nomor RM yang tetap aktif — bukan nilai identitas di atas.</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach (['A' => $a, 'B' => $b] as $label => $patient)
                            <label class="flex items-start gap-2 rounded-lg border border-hairline p-3 text-sm">
                                <input type="radio" name="canonical_patient_id" value="{{ $patient->id }}" @checked($case->canonical_patient_id === $patient->id) @disabled(! $canEdit)>
                                <span>
                                    <span class="font-semibold text-navy">Pasien {{ $label }} — {{ $patient->medical_record_number ?? 'Belum ada RM' }}</span>
                                    <span class="block text-xs text-ink-soft">{{ $patient->branchLabel() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @if ($canEdit)
                    <x-ui.button type="submit" variant="secondary">Simpan Rekonsiliasi</x-ui.button>
                @endif
            </div>
            @if ($canEdit)</form>@endif
        </x-ui.card>

        {{-- Merge preview --}}
        <x-ui.card title="Preview penggabungan" description="Data berikut dipindahkan kepemilikannya ke pasien canonical. Tidak ada data yang dihapus, digabung isinya, atau diubah nominalnya." padding="p-0">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Data</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien A</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien B</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Setelah merge</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline bg-surface">
                    @foreach ($preview['consolidated'] as $group => $row)
                        <tr>
                            <td class="px-4 py-2 text-sm text-navy">{{ $row['label'] }}</td>
                            <td class="px-4 py-2 text-right text-sm tabular-nums">{{ $row['a'] }}</td>
                            <td class="px-4 py-2 text-right text-sm tabular-nums">{{ $row['b'] }}</td>
                            <td class="px-4 py-2 text-right text-sm font-semibold tabular-nums">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <div class="grid gap-3 border-t border-hairline p-4 text-sm sm:grid-cols-3">
                <div><div class="text-xs text-ink-soft">Pasien canonical</div><div class="font-semibold text-navy">{{ $preview['canonical']?->medical_record_number ?? 'Belum dipilih' }}</div></div>
                <div><div class="text-xs text-ink-soft">RM alias yang dibuat</div><div class="font-semibold text-navy">{{ $preview['aliases'] === [] ? '—' : implode(', ', $preview['aliases']) }}</div></div>
                <div><div class="text-xs text-ink-soft">Ditandai MERGED</div><div class="font-semibold text-navy">{{ $preview['source']?->medical_record_number ?? '—' }}</div></div>
            </div>
        </x-ui.card>

        @if ($case->merged_at)
            <x-ui.card title="Hasil penggabungan">
                <p class="text-sm text-ink">Digabungkan {{ $case->merged_at->format('d-m-Y H:i') }} oleh {{ $case->reviewer?->name }}. Pasien {{ $case->sourcePatient?->medical_record_number }} kini menunjuk ke {{ $case->canonicalPatient?->medical_record_number }}.</p>
                @if ($case->reversal_assessment)
                    <div class="mt-3 text-sm">
                        <div class="font-semibold text-navy">Penilaian reversal:
                            {{ ($case->reversal_assessment['mode'] ?? '') === PatientMergeReversalService::MODE_SAFE ? 'Aman dijalankan otomatis' : 'Memerlukan rekonsiliasi manual yang diawasi' }}</div>
                        @foreach ($case->reversal_assessment['reasons'] ?? [] as $reason)<div class="text-ink-soft">• {{ $reason }}</div>@endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Actions --}}
        <div class="grid gap-4 lg:grid-cols-2">
            @can('submit', $case)
                <x-ui.card title="Kirim untuk review">
                    <form method="POST" action="{{ route('patient-merge.cases.submit', $case) }}">@csrf
                        <x-ui.button type="submit" :disabled="$blockers !== []">Kirim untuk Review</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('withdraw', $case)
                <x-ui.card title="Tarik kembali ke Draf">
                    <form method="POST" action="{{ route('patient-merge.cases.withdraw', $case) }}">@csrf
                        <x-ui.button type="submit" variant="secondary">Tarik Kembali</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('review', $case)
                <x-ui.card title="Setujui &amp; gabungkan">
                    @if ((int) $case->requested_by === (int) auth()->id())
                        <p class="text-sm text-ink-soft">Anda adalah pengaju. Penggabungan harus disetujui oleh peninjau lain.</p>
                    @else
                        <form method="POST" action="{{ route('patient-merge.cases.approve', $case) }}" class="space-y-3">@csrf
                            <x-ui.textarea name="review_note" label="Catatan peninjau (opsional)" rows="2" />
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm_merge" value="1" required>
                                <span>Saya telah meninjau preview, identitas final, dan jumlah data. Saya memahami penggabungan memindahkan seluruh riwayat ke pasien canonical.</span></label>
                            <x-ui.button type="submit" :disabled="$blockers !== []">Setujui &amp; Gabungkan</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
                <x-ui.card title="Tolak pengajuan">
                    <form method="POST" action="{{ route('patient-merge.cases.reject', $case) }}" class="space-y-3">@csrf
                        <x-ui.textarea name="reason" label="Alasan penolakan" rows="2" required />
                        <x-ui.button type="submit" variant="danger">Tolak</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('cancel', $case)
                <x-ui.card title="Batalkan pengajuan">
                    <form method="POST" action="{{ route('patient-merge.cases.cancel', $case) }}" class="space-y-3">@csrf
                        <x-ui.textarea name="reason" label="Alasan pembatalan" rows="2" required />
                        <x-ui.button type="submit" variant="secondary">Batalkan</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('reverse', $case)
                @if ($case->status === PatientMergeStatus::COMPLETED)
                    <x-ui.card title="Ajukan review reversal">
                        <p class="mb-3 text-sm text-ink-soft">Bukan "undo": sistem menilai apakah reversal dapat dibuktikan aman. Jika pasien canonical sudah menerima aktivitas baru, reversal memerlukan rekonsiliasi manual yang diawasi.</p>
                        <form method="POST" action="{{ route('patient-merge.cases.reversal.request', $case) }}" class="space-y-3">@csrf
                            <x-ui.textarea name="reason" label="Alasan reversal" rows="2" required />
                            <x-ui.button type="submit" variant="secondary">Ajukan Review Reversal</x-ui.button>
                        </form>
                    </x-ui.card>
                @elseif ($case->status === PatientMergeStatus::REVERSAL_REQUIRED)
                    <x-ui.card title="Review reversal">
                        @if (($case->reversal_assessment['mode'] ?? '') === PatientMergeReversalService::MODE_SAFE && (int) $case->reversal_requested_by !== (int) auth()->id())
                            <form method="POST" action="{{ route('patient-merge.cases.reversal.execute', $case) }}" class="mb-3">@csrf
                                <x-ui.button type="submit" variant="danger">Jalankan Reversal</x-ui.button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('patient-merge.cases.reversal.dismiss', $case) }}">@csrf
                            <x-ui.button type="submit" variant="secondary">Tutup Review (penggabungan tetap berlaku)</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan
        </div>
    </div>
</x-settings-shell>
