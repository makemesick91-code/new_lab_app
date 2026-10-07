{{-- Shared server-paginated case list. Names, Nomor RM and status only. --}}
@php
    use App\Modules\PatientMerge\Support\PatientMergeStatus;
@endphp
<x-ui.filter-bar :action="$action">
    <div class="md:w-48">
        <x-ui.select name="status" label="Status">
            <option value="">Semua</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ PatientMergeStatus::label($status) }}</option>
            @endforeach
        </x-ui.select>
    </div>
    <div class="md:w-56">
        <x-ui.input name="rm" label="Nomor RM (pasien / canonical)" :value="$filters['rm'] ?? ''" />
    </div>
    <div class="md:w-40">
        <x-ui.input type="date" name="date_from" label="Dari tanggal" :value="$filters['date_from'] ?? ''" />
    </div>
    <div class="md:w-40">
        <x-ui.input type="date" name="date_to" label="Sampai tanggal" :value="$filters['date_to'] ?? ''" />
    </div>
    @if ($showRisk ?? true)
        <div class="md:w-40">
            <x-ui.select name="risk" label="Risiko">
                <option value="">Semua</option>
                <option value="high" @selected(($filters['risk'] ?? '') === 'high')>Tinggi</option>
                <option value="normal" @selected(($filters['risk'] ?? '') === 'normal')>Normal</option>
            </x-ui.select>
        </div>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit">Terapkan</x-ui.button>
        <x-ui.button variant="secondary" :href="$action">Reset</x-ui.button>
    </x-slot:actions>
</x-ui.filter-bar>

<x-ui.card padding="p-0">
    @if ($cases->isEmpty())
        <div class="p-6"><x-ui.empty-state :title="$emptyTitle ?? 'Belum ada pengajuan'" description="Tidak ada data yang cocok dengan filter ini dalam cakupan cabang Anda." /></div>
    @else
        <x-ui.table>
            <thead class="bg-navy-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">No. Pengajuan</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien A</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien B</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Canonical</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pengaju / Peninjau</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline bg-surface">
                @foreach ($cases as $case)
                    <tr>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-navy">{{ $case->case_number }}</div>
                            <div class="text-xs text-ink-muted">{{ $case->created_at?->format('d-m-Y H:i') }}</div>
                            @if ($case->risk_level === 'high')<x-ui.badge tone="warning">Risiko tinggi</x-ui.badge>@endif
                            @if ($case->cross_branch)<x-ui.badge tone="info">Lintas cabang</x-ui.badge>@endif
                        </td>
                        @foreach ([$case->patientA, $case->patientB] as $patient)
                            <td class="px-4 py-3 text-sm">
                                <div class="text-navy">{{ $patient?->name }}</div>
                                <div class="text-xs text-ink-soft">{{ $patient?->medical_record_number ?? 'Belum ada RM' }}</div>
                            </td>
                        @endforeach
                        <td class="px-4 py-3 text-sm text-ink">{{ $case->canonicalPatient?->medical_record_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">@include('patient-merge.partials.status-badge', ['status' => $case->status])</td>
                        <td class="px-4 py-3 text-xs text-ink-soft">
                            <div>{{ $case->requester?->name }}</div>
                            <div>{{ $case->reviewer?->name ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button size="sm" variant="secondary" :href="route('patient-merge.cases.show', $case)">Buka</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="border-t border-hairline p-4">{{ $cases->links() }}</div>
    @endif
</x-ui.card>
