<x-settings-shell title="Odontogram">
    <div class="space-y-6">

        {{-- Session Flash --}}
        @if (session('status'))
            <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Header --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Odontogram</h3>
                        <p class="text-sm text-gray-500">
                            {{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                        {{ $odontogram->status === 'draft' ? 'Draft' : ucfirst($odontogram->status) }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex gap-3">
                    <a href="{{ route('rme.visits.show', $clinicVisit) }}"
                       class="inline-flex items-center rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                        &larr; Kembali ke Kunjungan
                    </a>
                </div>
            </div>
        </div>

        {{-- Placeholder Info --}}
        <div class="rounded-md bg-blue-50 border border-blue-200 p-4">
            <p class="text-sm font-medium text-blue-800">Odontogram Placeholder</p>
            <p class="mt-1 text-sm text-blue-700">
                Odontogram interaktif belum tersedia. Placeholder ini disiapkan untuk Sprint berikutnya.
                Anda dapat menyimpan catatan sementara di bawah ini.
            </p>
        </div>

        {{-- Placeholder Notes Form --}}
        @can('update', $odontogram)
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h4 class="text-base font-semibold text-gray-900 mb-4">Catatan Placeholder</h4>
                    <form method="POST" action="{{ route('rme.odontograms.update', $odontogram) }}">
                        @csrf
                        @method('PATCH')

                        <div class="space-y-4">
                            <div>
                                <label for="summary_notes" class="block text-sm font-medium text-gray-700">
                                    Catatan Ringkasan
                                </label>
                                <textarea
                                    id="summary_notes"
                                    name="summary_notes"
                                    rows="5"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm @error('summary_notes') border-red-300 @enderror"
                                    maxlength="5000"
                                    placeholder="Catatan kondisi gigi sementara…">{{ old('summary_notes', $odontogram->summary_notes) }}</textarea>
                                @error('summary_notes')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                    Simpan Catatan Placeholder
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @else
            {{-- Read-only notes for viewer --}}
            @if ($odontogram->summary_notes)
                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h4 class="text-base font-semibold text-gray-900 mb-2">Catatan Ringkasan</h4>
                        <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $odontogram->summary_notes }}</p>
                    </div>
                </div>
            @endif
        @endcan

        {{-- Future: Tooth Map Section (disabled placeholder) --}}
        <div class="bg-white shadow-sm sm:rounded-lg border border-dashed border-gray-300">
            <div class="p-6 text-center text-gray-400">
                <p class="text-sm font-medium">Tooth Map</p>
                <p class="mt-1 text-xs">Peta gigi interaktif akan dikembangkan di phase berikutnya (Sprint 20 Phase 1.3.2).</p>
            </div>
        </div>

    </div>
</x-settings-shell>
