@php
    $medicalRecord = $visit->medicalRecord;
    $odontogram = $visit->odontogram;
    // Sprint 60 — render every RM page (Page 1 legacy read-through + Page 2+).
    $rmPages = $medicalRecord ? $medicalRecord->orderedHandwritingPages()->filter(fn ($p) => $p['has_content']) : collect();
    $statusLabels = [
        'normal' => 'Normal',
        'caries' => 'Karies',
        'missing' => 'Hilang',
        'crown' => 'Crown',
        'root_treated' => 'PSA',
        'mobility' => 'Goyang',
        'impaction' => 'Impaksi',
        'filling' => 'Tambalan',
    ];
    $conditionLabels = $statusLabels;
    $teethData = $odontogram?->tooth_map_payload['teeth'] ?? [];
    $markedTeeth = [];
    foreach ($teethData as $toothNum => $td) {
        $td = (array) $td;
        if (! empty($td['status']) || ! empty($td['conditions']) || (isset($td['note']) && $td['note'] !== '')) {
            $markedTeeth[(string) $toothNum] = $td;
        }
    }
    ksort($markedTeeth, SORT_NATURAL);
@endphp

<x-ui.card title="Ringkasan Klinis (Dokter)">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-gray-500">No. Kunjungan</dt>
            <dd class="font-mono font-medium text-gray-900">{{ $visit->visit_number }}</dd>
        </div>
        @if (! $visit->isNewVisit())
            <div>
                <dt class="text-gray-500">Jenis Kunjungan</dt>
                <dd class="text-gray-900">{{ $visit->visitTypeLabel() }}</dd>
            </div>
        @endif
        <div>
            <dt class="text-gray-500">Tanggal</dt>
            <dd class="text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Pasien</dt>
            <dd class="font-medium text-gray-900">{{ $visit->patient?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Nomor WA</dt>
            <dd class="text-gray-900">{{ $visit->patient?->whatsapp_number ?? '—' }}</dd>
            <dd class="mt-0.5 text-xs text-gray-400">Follow-up WhatsApp dilakukan manual oleh kasir. Sistem tidak mengirim pesan WhatsApp otomatis.</dd>
        </div>
        <div>
            <dt class="text-gray-500">Persetujuan Tindakan Medis</dt>
            <dd class="mt-1">
                @if ($visit->hasSignedConsentDocument())
                    <x-ui.badge tone="success">Sudah ditandatangani</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Tidak tercatat</x-ui.badge>
                @endif
            </dd>
            {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03 — informational
                 only. Consent is taken by the doctor at the start of the examination
                 and gates RME authoring; it is NOT a condition of payment and the
                 cashier has nothing to verify here. --}}
            <dd class="mt-0.5 text-xs text-gray-400">Ditandatangani saat pemeriksaan dokter. Bukan syarat pembayaran.</dd>
        </div>
        <div>
            <dt class="text-gray-500">Dokter</dt>
            <dd class="text-gray-900">{{ $visit->doctor?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Klinik/Cabang</dt>
            <dd class="text-gray-900">{{ $visit->branch ? $visit->branch->code.' — '.$visit->branch->name : '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Layanan Awal (Triase)</dt>
            <dd class="text-gray-900">{{ $visit->initialTreatment?->name ?? '—' }}</dd>
        </div>
        @if ($visit->initial_service_note)
            <div class="sm:col-span-2">
                <dt class="text-gray-500">Catatan Layanan Awal</dt>
                <dd class="text-gray-700 whitespace-pre-wrap">{{ $visit->initial_service_note }}</dd>
            </div>
        @endif
        @if ($medicalRecord)
            <div>
                <dt class="text-gray-500">Status RME</dt>
                <dd class="mt-0.5">
                    <x-ui.badge :tone="$medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'success' : 'warning'">
                        {{ strtoupper($medicalRecord->status) }}
                    </x-ui.badge>
                </dd>
            </div>
        @endif
    </dl>

    @if ($rmPages->isNotEmpty())
        <div class="mt-5 border-t border-gray-100 pt-5">
            <h4 class="text-sm font-semibold text-gray-900">RME Tulisan Tangan</h4>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($rmPages as $rmPage)
                    <div>
                        <p class="text-xs text-gray-500">Halaman {{ $rmPage['page_number'] }}
                            @if ($rmPage['saved_at'])
                                &mdash; Tersimpan {{ $rmPage['saved_at']->format('d/m/Y H:i') }}
                            @endif
                        </p>
                        <img src="{{ $rmPage['preview_url'] }}" alt="RME tulisan tangan dokter halaman {{ $rmPage['page_number'] }}"
                             class="mt-1 max-h-64 rounded-lg border border-gray-200 bg-white object-contain" />
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($odontogram)
        <div class="mt-5 border-t border-gray-100 pt-5 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="text-sm font-semibold text-gray-900">Odontogram</h4>
                <x-ui.badge :tone="$odontogram->isFinalized() ? 'success' : 'warning'">
                    {{ $odontogram->isFinalized() ? 'Final' : 'Draft' }}
                </x-ui.badge>
            </div>

            @if ($odontogram->additional_conditions)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kondisi Tambahan</dt>
                    <dd class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">{{ $odontogram->additional_conditions }}</dd>
                </div>
            @endif

            @if ($odontogram->summary_notes)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Catatan Odontogram</dt>
                    <dd class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">{{ $odontogram->summary_notes }}</dd>
                </div>
            @endif

            @if (count($markedTeeth) > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-3 py-2 font-medium">Gigi</th>
                                <th scope="col" class="px-3 py-2 font-medium">Kondisi</th>
                                <th scope="col" class="px-3 py-2 font-medium">Tanda Klinis</th>
                                <th scope="col" class="px-3 py-2 font-medium">Catatan Gigi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($markedTeeth as $toothNum => $td)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ $toothNum }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ?? '—') }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        @if (! empty($td['conditions']) && is_array($td['conditions']))
                                            {{ implode(', ', array_map(fn ($c) => $conditionLabels[$c] ?? $c, $td['conditions'])) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-gray-700 whitespace-pre-wrap">{{ $td['note'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500 italic">Belum ada kondisi odontogram yang dipilih.</p>
            @endif
        </div>
    @endif
</x-ui.card>
