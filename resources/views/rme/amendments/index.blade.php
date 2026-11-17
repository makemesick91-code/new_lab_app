@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeAmendment\Models\RmeAmendment;
@endphp
{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 (rule 240 §6) — the supervisor
     queue of amendment requests, branch-scoped by RmeWorkingBranchScope (CG-05).
     Patient identity here is the name and the medical record number only (CG-11). --}}
<x-settings-shell title="Amandemen RME">
    <div class="space-y-6">
        <x-ui.page-header title="Amandemen RME" subtitle="Permintaan koreksi rekam medis dan odontogram yang sudah final. Setiap keputusan memerlukan alasan tertulis.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Supervisi</x-slot:breadcrumb>
        </x-ui.page-header>

        <div class="flex flex-wrap gap-2 text-sm" data-amendment-status-counts>
            <a href="{{ route('rme.amendments.index') }}" class="rounded border border-hairline px-3 py-1 {{ $status === null ? 'bg-navy-50 font-semibold' : '' }}">Semua</a>
            @foreach (RmeAmendment::STATUSES as $statusOption)
                <a href="{{ route('rme.amendments.index', ['status' => $statusOption]) }}" class="rounded border border-hairline px-3 py-1 {{ $status === $statusOption ? 'bg-navy-50 font-semibold' : '' }}">
                    {{ RmeAmendment::STATUS_LABELS[$statusOption] }} <span class="tabular-nums">({{ $counts[$statusOption] ?? 0 }})</span>
                </a>
            @endforeach
        </div>

        @if ($rows->isEmpty())
            <x-ui.empty-state title="Belum ada permintaan amandemen" description="Tidak ada permintaan amandemen pada cabang Anda." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No.</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Diajukan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Pasien</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Dokumen</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Pengaju</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($rows as $row)
                            <tr data-amendment-row="{{ $row->id }}">
                                <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('rme.amendments.show', $row) }}">#{{ $row->id }}</a></td>
                                <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($row->created_at, 'd/m/Y H:i') }} WITA</td>
                                <td class="px-4 py-3">{{ $row->branch?->name }}</td>
                                <td class="px-4 py-3">{{ $row->patient?->name }} <span class="font-mono text-ink-soft">{{ $row->patient?->medical_record_number }}</span></td>
                                <td class="px-4 py-3">{{ $row->subjectLabel() }} · versi {{ $row->base_version }}</td>
                                <td class="px-4 py-3">{{ $row->requester?->name }}</td>
                                <td class="px-4 py-3">{{ $row->statusLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <div class="mt-3">{{ $rows->links() }}</div>
        @endif
    </div>
</x-settings-shell>
