<x-settings-shell title="Dashboard RME">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">Laporan Pembayaran RME</h2>
        </div>

        <form method="GET" action="{{ route('rme.reports.payments') }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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
                    <label for="date_from" class="block text-xs text-gray-500">Tanggal Kunjungan Dari</label>
                    <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label for="date_to" class="block text-xs text-gray-500">Tanggal Kunjungan Sampai</label>
                    <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label for="payment_method_id" class="block text-xs text-gray-500">Metode Pembayaran</label>
                    <select id="payment_method_id" name="payment_method_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Semua metode</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}" @selected(($filters['payment_method_id'] ?? null) == $method->id)>{{ $method->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="treatment_id" class="block text-xs text-gray-500">Treatment</label>
                    <select id="treatment_id" name="treatment_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Semua treatment</option>
                        @foreach ($treatments as $treatment)
                            <option value="{{ $treatment->id }}" @selected(($filters['treatment_id'] ?? null) == $treatment->id)>{{ $treatment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="doctor_id" class="block text-xs text-gray-500">Dokter</label>
                    <select id="doctor_id" name="doctor_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Semua dokter</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected(($filters['doctor_id'] ?? null) == $doctor->id)>{{ $doctor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="q" class="block text-xs text-gray-500">Search ID / Nama Pasien</label>
                    <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ID, RM, atau nama pasien" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="submit" class="rounded-md bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-600">Filter</button>
                <a href="{{ route('rme.reports.payments') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </div>
        </form>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-green-50 px-3 py-1 text-green-700">Total Diterima: <strong>{{ format_currency_id($totalAmount) }}</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Invoice</th>
                    <th class="px-3 py-2 font-medium">ID / RM Pasien</th>
                    <th class="px-3 py-2 font-medium">Nama Pasien</th>
                    <th class="px-3 py-2 font-medium">Metode Pembayaran</th>
                    <th class="px-3 py-2 font-medium">Treatment</th>
                    <th class="px-3 py-2 font-medium">Dokter</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($payments as $p)
                        @php
                            $treatmentNames = $p->rmeInvoice?->items
                                ?->pluck('treatment.name')
                                ->filter()
                                ->unique()
                                ->values();
                            $doctorNames = collect([$p->clinicVisit?->doctor?->name])
                                ->merge($p->rmeInvoice?->items?->pluck('doctor.name') ?? [])
                                ->filter()
                                ->unique()
                                ->values();
                        @endphp
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $p->rmeInvoice?->invoice_number ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-600">{{ $p->patient?->medical_record_number ?? ('#'.$p->patient_id) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->paymentMethod?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $treatmentNames?->isNotEmpty() ? $treatmentNames->join(', ') : '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $doctorNames->isNotEmpty() ? $doctorNames->join(', ') : '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->branch?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->rmeInvoice?->status ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($p->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada pembayaran RME.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-settings-shell>
