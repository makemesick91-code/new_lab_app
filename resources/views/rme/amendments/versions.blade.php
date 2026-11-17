{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 (rule 240 §7) — the version
     history of a final sheet or a finalized chart, read like the record itself
     (RmeAmendmentService::versionHistoryFor(): 404 outside its scope). Snapshots
     are shown on the version page; nothing here names an object key. --}}
@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeAmendment\Models\RmeRecordVersion;

    $kindLabels = [
        RmeRecordVersion::KIND_FINAL => 'Final',
        RmeRecordVersion::KIND_BASELINE => 'Dasar (difinalkan sebelum aturan amandemen)',
        RmeRecordVersion::KIND_AMENDMENT => 'Amandemen',
    ];
    $subjectLabel = $isChart ? 'Odontogram' : 'Rekam Medis';
    $recordUrl = $isChart
        ? route('rme.visits.odontogram.show', $subject->clinic_visit_id)
        : route('rme.visits.medical-record.show', [$subject->clinic_visit_id, 'sheet' => $subject->id]);
    $versionRoute = $isChart ? 'rme.amendments.versions.chart.show' : 'rme.amendments.versions.sheet.show';
@endphp
<x-settings-shell title="Riwayat Versi {{ $subjectLabel }}">
    <div class="space-y-6">
        <x-ui.page-header :title="'Riwayat Versi '.$subjectLabel" :subtitle="$amendmentCount > 0 ? 'Diamandemen '.$amendmentCount.'×' : 'Belum pernah diamandemen'">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Amandemen</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="$recordUrl">Buka {{ $subjectLabel }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if ($versions->isEmpty())
            <x-ui.empty-state title="Belum ada versi" description="Versi pertama ditulis saat rekam medis difinalkan, atau saat amandemen pertama diajukan untuk rekam medis yang difinalkan sebelum aturan amandemen." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Versi</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Jenis</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Dicatat</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($versions as $version)
                            <tr data-version-row="{{ $version->version }}">
                                <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route($versionRoute, [$subject->id, $version->version]) }}">Versi {{ $version->version }}</a></td>
                                <td class="px-4 py-3">{{ $kindLabels[$version->kind] ?? $version->kind }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($version->created_at, 'd/m/Y H:i') }} WITA</td>
                                <td class="px-4 py-3">{{ $version->creator?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
