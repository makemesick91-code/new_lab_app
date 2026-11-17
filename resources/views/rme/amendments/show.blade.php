@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeAmendment\Models\RmeRecordVersion;
@endphp
{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 (rule 240 §6) — one amendment:
     the version it was requested against beside the proposal, the reason, the
     decision, and the actions the service would accept from this account
     (`$actions` from RmeAmendmentService::actionsFor()). Approve-and-apply is
     M3.3 PR5 and has no control here yet. --}}
@php
    $isChart = $amendment->subject_type === RmeRecordVersion::SUBJECT_ODONTOGRAM;
    $recordUrl = $isChart
        ? route('rme.visits.odontogram.show', $amendment->clinic_visit_id)
        : route('rme.visits.medical-record.show', [$amendment->clinic_visit_id, 'sheet' => $amendment->subject_id]);
@endphp
<x-settings-shell title="Amandemen #{{ $amendment->id }}">
    <div class="space-y-6" data-amendment-detail="{{ $amendment->id }}">
        <x-ui.page-header :title="'Amandemen #'.$amendment->id.' — '.$amendment->subjectLabel()" :subtitle="$amendment->statusLabel()">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Amandemen</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="$recordUrl">Buka {{ $amendment->subjectLabel() }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card title="Permintaan">
            <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                <div><dt class="text-ink-soft">Pasien</dt><dd>{{ $amendment->patient?->name }} <span class="font-mono text-ink-soft">{{ $amendment->patient?->medical_record_number }}</span></dd></div>
                <div><dt class="text-ink-soft">Cabang</dt><dd>{{ $amendment->branch?->name }}</dd></div>
                <div><dt class="text-ink-soft">Diajukan</dt><dd>{{ OversightFormat::clinicalTime($amendment->created_at, 'd/m/Y H:i') }} WITA — {{ $amendment->requester?->name }}</dd></div>
                <div>
                    <dt class="text-ink-soft">Versi dasar</dt>
                    <dd>
                        Versi {{ $amendment->base_version }}
                        @if ($latest_version !== null && (int) $latest_version !== (int) $amendment->base_version)
                            <span class="text-warning" data-amendment-stale>(versi terbaru: {{ $latest_version }})</span>
                        @endif
                    </dd>
                </div>
                <div class="md:col-span-2"><dt class="text-ink-soft">Alasan</dt><dd class="whitespace-pre-line">{{ $amendment->reason }}</dd></div>
                @if ($amendment->decided_at !== null)
                    <div><dt class="text-ink-soft">Diputuskan</dt><dd>{{ OversightFormat::clinicalTime($amendment->decided_at, 'd/m/Y H:i') }} WITA — {{ $amendment->decider?->name }}</dd></div>
                    <div class="md:col-span-2"><dt class="text-ink-soft">Alasan keputusan</dt><dd class="whitespace-pre-line">{{ $amendment->decision_reason }}</dd></div>
                @endif
                @if ($amendment->cancelled_at !== null)
                    <div><dt class="text-ink-soft">Dibatalkan pengaju</dt><dd>{{ OversightFormat::clinicalTime($amendment->cancelled_at, 'd/m/Y H:i') }} WITA</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Sebelum dan sesudah" description="Kiri: versi {{ $amendment->base_version }} yang tercatat. Kanan: usulan. Rekam medis tidak berubah sebelum amandemen disetujui dan diterapkan.">
            @if ($isChart)
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach (['Versi '.$amendment->base_version => $chart_before, 'Usulan' => $chart_after] as $heading => $chart)
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-navy">{{ $heading }}</h4>
                            <x-ui.table>
                                <thead class="bg-navy-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-ink-soft">Gigi</th>
                                        <th class="px-3 py-2 text-left font-medium text-ink-soft">Diagnosa</th>
                                        <th class="px-3 py-2 text-left font-medium text-ink-soft">Perawatan</th>
                                        <th class="px-3 py-2 text-left font-medium text-ink-soft">Dokter</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-hairline">
                                    @forelse ($chart['teeth'] as $tooth)
                                        <tr>
                                            <td class="px-3 py-2 font-semibold">{{ $tooth['tooth'] }}</td>
                                            <td class="px-3 py-2">{{ $tooth['status'] }} {{ $tooth['additional_condition'] }}</td>
                                            <td class="px-3 py-2">{{ $tooth['additional_note'] }}</td>
                                            <td class="px-3 py-2">{{ $tooth['dokter'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-2 text-ink-soft">Tidak ada data gigi.</td></tr>
                                    @endforelse
                                </tbody>
                            </x-ui.table>
                            <p class="mt-2 text-sm"><span class="text-ink-soft">Ringkasan:</span> {{ $chart['summary_notes'] ?? '—' }}</p>
                            <p class="text-sm"><span class="text-ink-soft">Kondisi tambahan:</span> {{ $chart['additional_conditions'] ?? '—' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                @if ($field_rows !== [])
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-ink-soft">Kolom</th>
                                <th class="px-3 py-2 text-left font-medium text-ink-soft">Versi {{ $amendment->base_version }}</th>
                                <th class="px-3 py-2 text-left font-medium text-ink-soft">Usulan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($field_rows as $fieldRow)
                                <tr class="align-top">
                                    <td class="px-3 py-2 font-medium">{{ $fieldRow['label'] }}</td>
                                    <td class="whitespace-pre-line px-3 py-2 text-ink-soft">{{ $fieldRow['before'] ?? '—' }}</td>
                                    <td class="whitespace-pre-line px-3 py-2">{{ $fieldRow['after'] ?? '— (dikosongkan)' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif

                <div class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                    <div>
                        <h4 class="mb-1 font-semibold text-navy">Diagnosis versi {{ $amendment->base_version }}</h4>
                        <ul class="list-disc pl-5 text-ink-soft">
                            @forelse ($diagnoses_before as $label)<li>{{ $label }}</li>@empty<li>Tidak ada.</li>@endforelse
                        </ul>
                    </div>
                    <div>
                        <h4 class="mb-1 font-semibold text-navy">Diusulkan dihapus</h4>
                        <ul class="list-disc pl-5">
                            @forelse ($diagnoses_removed as $label)<li>{{ $label }}</li>@empty<li class="text-ink-soft">Tidak ada.</li>@endforelse
                        </ul>
                    </div>
                    <div>
                        <h4 class="mb-1 font-semibold text-navy">Diusulkan ditambah</h4>
                        <ul class="list-disc pl-5">
                            @forelse ($diagnoses_added as $label)<li>{{ $label }}</li>@empty<li class="text-ink-soft">Tidak ada.</li>@endforelse
                        </ul>
                    </div>
                </div>
            @endif
        </x-ui.card>

        @if ($actions['can_cancel'])
            <x-ui.card title="Batalkan permintaan">
                <form method="POST" action="{{ route('rme.amendments.cancel', $amendment) }}" data-amendment-cancel>
                    @csrf
                    <p class="mb-3 text-sm text-ink-soft">Permintaan ini ditarik dan rekam medis tetap seperti semula.</p>
                    @error('amendment')<p class="mb-2 text-sm text-danger">{{ $message }}</p>@enderror
                    <x-ui.button type="submit" variant="secondary">Batalkan Permintaan</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($actions['can_reject'])
            <x-ui.card title="Keputusan Supervisor RME">
                <form method="POST" action="{{ route('rme.amendments.reject', $amendment) }}" class="space-y-3" data-amendment-reject>
                    @csrf
                    <x-ui.textarea name="decision_reason" label="Alasan penolakan (10–1000 karakter)" rows="3" required :value="old('decision_reason')" />
                    @error('amendment')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <x-ui.button type="submit" variant="danger">Tolak Amandemen</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
