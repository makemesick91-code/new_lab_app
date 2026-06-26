<x-settings-shell title="Audit Data Pasien">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Audit Kelengkapan Data Pasien</h2>
                    <p class="text-sm text-gray-500">Read-only — audit ini tidak mengubah data pasien. No. KTP tidak ditampilkan penuh.</p>
                </div>
                <a href="{{ route('rme.patients.audit.export', request()->query()) }}"
                   class="rounded-md border border-teal-700 px-3 py-2 text-sm font-medium text-teal-700 hover:bg-teal-50">Export CSV</a>
            </div>

            {{-- KPI cards --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500">Total Pasien</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($kpi['total']) }}</p>
                </div>
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-xs text-emerald-700">Pasien Lengkap</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ number_format($kpi['complete']) }}</p>
                </div>
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs text-amber-700">Pasien Tidak Lengkap</p>
                    <p class="mt-1 text-2xl font-semibold text-amber-700">{{ number_format($kpi['incomplete']) }}</p>
                </div>
                <div class="rounded-lg border border-teal-200 bg-teal-50 p-4">
                    <p class="text-xs text-teal-700">Persentase Kelengkapan</p>
                    <p class="mt-1 text-2xl font-semibold text-teal-700">{{ $kpi['completeness_percentage'] }}%</p>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 text-sm">
                <span class="rounded-md bg-gray-50 px-3 py-2 text-gray-700">Missing No. RM: <strong>{{ number_format($kpi['missing_rm']) }}</strong></span>
                <span class="rounded-md bg-gray-50 px-3 py-2 text-gray-700">Missing HP/WA: <strong>{{ number_format($kpi['missing_contact']) }}</strong></span>
                <span class="rounded-md bg-gray-50 px-3 py-2 text-gray-700">Missing Alamat: <strong>{{ number_format($kpi['missing_address']) }}</strong></span>
                <span class="rounded-md bg-gray-50 px-3 py-2 text-gray-700">Missing Tgl Lahir: <strong>{{ number_format($kpi['missing_birth_date']) }}</strong></span>
                <span class="rounded-md bg-rose-50 px-3 py-2 text-rose-700">Potensi Duplikat: <strong>{{ number_format($kpi['duplicate_risk']) }}</strong></span>
            </div>

            @if ($kpi['per_branch']->isNotEmpty())
                <div class="flex flex-wrap gap-2 text-xs">
                    @foreach ($kpi['per_branch'] as $branchName => $count)
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-blue-700">{{ $branchName }}: <strong>{{ number_format($count) }}</strong></span>
                    @endforeach
                </div>
            @endif

            {{-- Filters --}}
            <form method="GET" action="{{ route('rme.patients.audit') }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="branch_id" class="block text-xs text-gray-500">Cabang</label>
                        <select id="branch_id" name="branch_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Semua cabang RME</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="status" class="block text-xs text-gray-500">Status Kelengkapan</label>
                        <select id="status" name="status" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Semua status</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="missing_field" class="block text-xs text-gray-500">Jenis Data Kurang</label>
                        <select id="missing_field" name="missing_field" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Semua</option>
                            @foreach ($missingFieldOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['missing_field'] ?? null) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="active" class="block text-xs text-gray-500">Status Pasien</label>
                        <select id="active" name="active" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Semua</option>
                            <option value="active" @selected(($filters['active'] ?? null) === 'active')>Aktif</option>
                            <option value="inactive" @selected(($filters['active'] ?? null) === 'inactive')>Nonaktif</option>
                        </select>
                    </div>
                    <div>
                        <label for="date_from" class="block text-xs text-gray-500">Tgl Daftar Dari</label>
                        <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label for="date_to" class="block text-xs text-gray-500">Tgl Daftar Sampai</label>
                        <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label for="sort" class="block text-xs text-gray-500">Urutkan</label>
                        <select id="sort" name="sort" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Terbaru</option>
                            <option value="oldest" @selected(($filters['sort'] ?? null) === 'oldest')>Terlama</option>
                            <option value="most_incomplete" @selected(($filters['sort'] ?? null) === 'most_incomplete')>Paling Tidak Lengkap</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="q" class="block text-xs text-gray-500">Cari Nama / No. RM / No. HP</label>
                        <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nama, RM, atau nomor HP/WA" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 mt-5">
                        <input type="checkbox" name="duplicates_only" value="1" @checked($filters['duplicates_only'] ?? false) class="rounded border-gray-300" />
                        Hanya potensi duplikat
                    </label>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded-md bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-600">Filter</button>
                    <a href="{{ route('rme.patients.audit') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                </div>
            </form>

            {{-- Audit table --}}
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">No</th>
                        <th class="px-3 py-2 font-medium">Cabang</th>
                        <th class="px-3 py-2 font-medium">No. RM</th>
                        <th class="px-3 py-2 font-medium">Nama Pasien</th>
                        <th class="px-3 py-2 font-medium">Gender</th>
                        <th class="px-3 py-2 font-medium">TTL / Usia</th>
                        <th class="px-3 py-2 font-medium">HP/WA</th>
                        <th class="px-3 py-2 font-medium">Alamat</th>
                        <th class="px-3 py-2 font-medium">KTP</th>
                        <th class="px-3 py-2 font-medium">Data Kurang</th>
                        <th class="px-3 py-2 font-medium">Skor</th>
                        <th class="px-3 py-2 font-medium">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($patient = $row['patient'])
                            @php($eval = $row['evaluation'])
                            <tr>
                                <td class="px-3 py-2 text-gray-500">{{ $loop->iteration + ($rows->currentPage() - 1) * $rows->perPage() }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $patient->branch?->name ?? 'Tanpa Cabang' }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-600">{{ $patient->medical_record_number ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-900">
                                    {{ $patient->name ?? '—' }}
                                    @if ($row['duplicate_reasons'])
                                        <span class="ml-1 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700"
                                              title="{{ implode(', ', $row['duplicate_reasons']) }}">Duplikat?</span>
                                    @endif
                                    @unless ($patient->is_active)
                                        <span class="ml-1 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Nonaktif</span>
                                    @endunless
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ $patient->gender ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">
                                    @if ($patient->date_of_birth)
                                        {{ $patient->date_of_birth->format('d/m/Y') }} ({{ $patient->age() }} th)
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if (trim((string) $patient->phone) !== '' || trim((string) $patient->whatsapp_number) !== '')
                                        <span class="text-emerald-600">Ada</span>
                                    @else
                                        <span class="text-rose-600">Kosong</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if (trim((string) $patient->address) !== '')
                                        <span class="text-emerald-600">Ada</span>
                                    @else
                                        <span class="text-rose-600">Kosong</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $row['masked_ktp'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">
                                    @if ($eval['missing_fields'])
                                        {{ implode(', ', $eval['missing_fields']) }}
                                    @else
                                        <span class="text-emerald-600">Lengkap</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700' => $eval['score'] >= 90,
                                        'bg-amber-100 text-amber-700' => $eval['score'] >= 60 && $eval['score'] < 90,
                                        'bg-rose-100 text-rose-700' => $eval['score'] < 60,
                                    ])>{{ $eval['score'] }}%</span>
                                </td>
                                <td class="px-3 py-2">
                                    @can('update', $patient)
                                        <a href="{{ route('settings.patients.edit', $patient) }}" class="text-teal-700 hover:underline">Edit</a>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="px-3 py-6 text-center text-gray-400">Tidak ada pasien sesuai filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $rows->links() }}</div>
        </div>

        {{-- RM gap review --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Tinjauan Loncatan No. RM per Cabang</h3>
                <p class="text-sm text-gray-500">Format RM: <span class="font-mono">DG-{KODE_CABANG}-{TAHUN}-{NOMOR}</span>. RM yang tidak ber-format DG dilewati dan tidak menghentikan analisis.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">Cabang</th>
                        <th class="px-3 py-2 font-medium">Min</th>
                        <th class="px-3 py-2 font-medium">Max</th>
                        <th class="px-3 py-2 font-medium">Jumlah RM Valid</th>
                        <th class="px-3 py-2 font-medium">RM Tidak Terbaca</th>
                        <th class="px-3 py-2 font-medium">Nomor Hilang</th>
                        <th class="px-3 py-2 font-medium">Contoh Nomor Hilang</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rmGap as $gap)
                            <tr>
                                <td class="px-3 py-2 text-gray-900">{{ $gap['branch_code'] }} — {{ $gap['branch_name'] }}</td>
                                @if (! $gap['parseable'])
                                    <td colspan="6" class="px-3 py-2 text-amber-600">{{ $gap['note'] }}</td>
                                @else
                                    <td class="px-3 py-2 text-gray-600">{{ $gap['min'] }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $gap['max'] }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ number_format($gap['parseable_count']) }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ number_format($gap['unparseable_count']) }}</td>
                                    <td class="px-3 py-2 font-medium {{ $gap['missing_count'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format($gap['missing_count']) }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-500">
                                        @if ($gap['note'])
                                            {{ $gap['note'] }}
                                        @elseif ($gap['missing_sample'])
                                            {{ implode(', ', $gap['missing_sample']) }}{{ $gap['missing_count'] > count($gap['missing_sample']) ? ', …' : '' }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada cabang RME untuk ditinjau.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
