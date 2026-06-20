{{--
    Cross-branch Nomor RM lookup (Sprint 57).
    Read-only, exact-match lookup across ALL branches. Privacy-safe:
    no KTP / WhatsApp / address / clinical data is ever rendered here.
    Expects: $rmLookup (array from CrossBranchPatientLookupService).
--}}
@php
    $rmLookup = $rmLookup ?? ['searched' => false, 'query' => '', 'results' => [], 'is_duplicate' => false];
@endphp

<x-ui.card padding="p-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 class="text-base font-semibold text-gray-900">Cek Nomor RM Seluruh Cabang</h3>
            <p class="mt-1 text-sm text-gray-500">Cek apakah Nomor RM sudah terdaftar di cabang mana pun (mencegah pendaftaran ganda).</p>
        </div>
    </div>

    <form method="GET" action="{{ request()->url() }}" class="mt-3">
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[14rem] flex-1">
                <label for="rm-lookup-input" class="text-sm font-medium text-gray-700">Nomor RM</label>
                <input id="rm-lookup-input" type="text" name="rm_lookup" value="{{ $rmLookup['query'] }}"
                       placeholder="Masukkan Nomor RM (pencarian persis)"
                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <x-ui.button type="submit" variant="primary">Cek RM</x-ui.button>
            @if ($rmLookup['searched'])
                <x-ui.button variant="secondary" :href="request()->url()">Atur Ulang</x-ui.button>
            @endif
        </div>
    </form>

    @if ($rmLookup['searched'])
        @if (count($rmLookup['results']) > 0)
            @if ($rmLookup['is_duplicate'])
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">
                    Ditemukan lebih dari satu pasien dengan Nomor RM ini — kemungkinan duplikat. Mohon verifikasi.
                </p>
            @endif
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-3 py-2">Nomor RM</th>
                            <th class="px-3 py-2">Nama Pasien</th>
                            <th class="px-3 py-2">Cabang</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Kunjungan Terakhir</th>
                            <th class="px-3 py-2">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rmLookup['results'] as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row['medical_record_number'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row['branch_label'] }}</td>
                                <td class="px-3 py-2">
                                    <x-ui.badge :tone="$row['is_active'] ? 'success' : 'neutral'">
                                        {{ $row['is_active'] ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-gray-700">{{ $row['latest_visit_date'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['is_current_branch'])
                                        <span class="text-sm text-teal-700">Pasien ditemukan di cabang saat ini</span>
                                    @else
                                        <span class="text-sm text-indigo-700">Pasien ditemukan di cabang lain</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">
                Nomor RM tidak ditemukan di cabang mana pun.
            </p>
        @endif
        <p class="mt-2 text-xs text-gray-400">KTP dan data sensitif tidak ditampilkan.</p>
    @endif
</x-ui.card>
