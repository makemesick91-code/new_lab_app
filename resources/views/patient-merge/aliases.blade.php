{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — RM Alias.
     Old Nomor RM that still resolve to a patient after a merge. --}}
<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="RM Alias" subtitle="Nomor RM lama hasil penggabungan. Nomor ini tidak dihapus dan tetap mengarah ke pasien canonical.">
            <x-slot:breadcrumb>Duplikasi Pasien / RM Alias</x-slot:breadcrumb>
        </x-ui.page-header>
        @include('patient-merge.partials.nav')

        <x-ui.filter-bar :action="route('patient-merge.aliases.index')">
            <div class="md:w-64"><x-ui.input name="rm" label="Nomor RM lama / canonical" :value="$filters['rm'] ?? ''" /></div>
            <div class="md:w-44">
                <x-ui.select name="state" label="Status alias">
                    <option value="">Semua</option>
                    <option value="active" @selected(($filters['state'] ?? '') === 'active')>Aktif</option>
                    <option value="revoked" @selected(($filters['state'] ?? '') === 'revoked')>Dicabut (reversal)</option>
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('patient-merge.aliases.index')">Reset</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="p-0">
            @if ($aliases->isEmpty())
                <div class="p-6"><x-ui.empty-state title="Belum ada RM alias" description="RM alias dibuat otomatis ketika sebuah penggabungan selesai." /></div>
            @else
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">RM Lama (alias)</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Mengarah ke</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pengajuan</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Digabungkan</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline bg-surface">
                        @foreach ($aliases as $alias)
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-navy">{{ $alias->alias_medical_record_number }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="text-navy">{{ $alias->canonicalPatient?->medical_record_number }}</div>
                                    <div class="text-xs text-ink-soft">{{ $alias->canonicalPatient?->name }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($alias->mergeCase)
                                        <a class="text-brand-700 hover:underline" href="{{ route('patient-merge.cases.show', $alias->mergeCase->uuid) }}">{{ $alias->mergeCase->case_number }}</a>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-ink-soft">{{ $alias->merged_at?->format('d-m-Y H:i') }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($alias->revoked_at)<x-ui.badge tone="neutral">Dicabut</x-ui.badge>@else<x-ui.badge tone="success">Aktif</x-ui.badge>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <div class="border-t border-hairline p-4">{{ $aliases->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
