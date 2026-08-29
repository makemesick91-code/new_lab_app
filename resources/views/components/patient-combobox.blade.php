{{--
    REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1

    ONE control for choosing a registered patient. It replaces the old pair (a
    search box that only hid <option> elements, plus a native <select> carrying
    every patient in the estate) on "Kunjungan Baru".

    The visible field is a combobox; the only thing that ever submits is the
    hidden `patient_id`, and that is set exclusively by selecting a returned
    result. The result list is fetched from an authorized, branch-scoped,
    server-bounded endpoint — nothing about the patient set is rendered here.

    Dusk hooks `patient-search` / `patient-select` are preserved so the existing
    RME create smoke test keeps asserting the real control.
--}}
@props([
    'name' => 'patient_id',
    'endpoint',
    'selected' => null,
    'label' => null,
    'placeholder' => 'Cari nama atau nomor RM…',
    'required' => false,
])

@php
    $selectedValue = old($name, $selected);

    // Resolved through the same authorization boundary as the search itself, so
    // a crafted `?patient_id=` for another branch's patient prefills nothing.
    $selectedOption = filled($selectedValue)
        ? app(\App\Modules\Patient\Services\PatientSelectorSearchService::class)
            ->selectedOption(auth()->user(), (int) $selectedValue)
        : null;

    $selectedLabel = $selectedOption === null
        ? ''
        : trim($selectedOption['medical_record_number'] === ''
            ? $selectedOption['name']
            : $selectedOption['name'].' — '.$selectedOption['medical_record_number']);

    $inputId = 'patient-combobox-'.str()->uuid();
    $listboxId = $inputId.'-listbox';

    $comboboxConfig = [
        'endpoint' => $endpoint,
        'minLength' => \App\Modules\Patient\Services\PatientSelectorSearchService::MIN_QUERY_LENGTH,
        'debounceMs' => 300,
        'selected' => $selectedOption === null
            ? null
            : ['id' => $selectedOption['id'], 'label' => $selectedLabel],
    ];
@endphp

<div
    data-patient-combobox
    x-data="patientCombobox(@js($comboboxConfig))"
    x-on:patient-selector-reset.window="resetSelection()"
    @click.outside="closeDropdown()"
    {{ $attributes->merge(['class' => 'space-y-1']) }}
>
    @if ($label)
        <label for="{{ $inputId }}" class="block text-sm font-medium text-ink">
            {{ $label }}@if ($required) <span class="text-danger">*</span>@endif
        </label>
    @endif

    {{-- The canonical submitted value. Never typed into, never guessable from
         the visible text: the component clears it on every keystroke. --}}
    <input
        type="hidden"
        name="{{ $name }}"
        x-ref="patientId"
        data-patient-select
        dusk="patient-select"
        :value="selectedId"
    />

    <div class="relative">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-muted" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
            </span>

            <input
                id="{{ $inputId }}"
                type="text"
                autocomplete="off"
                dusk="patient-search"
                data-patient-search-input
                placeholder="{{ $placeholder }}"
                class="block w-full rounded-lg border-hairline pl-9 pr-9 text-sm focus:border-brand-500 focus:ring-brand-500"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $listboxId }}"
                :aria-expanded="open ? 'true' : 'false'"
                :value="query"
                @input="onInput($event.target.value)"
                @focus="onFocus()"
                @keydown="onKeydown($event)"
            />

            <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-ink-muted hover:text-ink"
                x-show="hasSelection || query !== ''"
                x-cloak
                @click.stop="clearSelection()"
                tabindex="-1"
                aria-label="Hapus pilihan pasien"
            >
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <div
            id="{{ $listboxId }}"
            role="listbox"
            x-show="showResults"
            x-cloak
            class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-hairline bg-white py-1 shadow-lg"
        >
            <template x-for="(result, index) in results" :key="result.id">
                <button
                    type="button"
                    role="option"
                    class="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50"
                    :class="{ 'bg-brand-50 text-brand-800': index === activeIndex }"
                    :aria-selected="index === activeIndex"
                    @mousedown.prevent="select(result)"
                    @mouseenter="activeIndex = index"
                >
                    <span class="font-medium text-ink" x-text="result.name"></span>
                    <span class="text-ink-soft" x-show="result.medical_record_number">
                        — <span class="font-mono" x-text="result.medical_record_number"></span>
                    </span>
                    <span class="block text-xs text-ink-muted" x-text="result.branch_label"></span>
                </button>
            </template>
        </div>

        <p x-show="loading" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-hairline bg-white px-3 py-2 text-sm text-ink-soft shadow-lg">
            Mencari pasien...
        </p>

        <p x-show="showTooShort" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-hairline bg-white px-3 py-2 text-sm text-ink-soft shadow-lg">
            Ketik minimal <span x-text="minLength"></span> karakter untuk mencari pasien.
        </p>

        <div x-show="showEmptyState" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-hairline bg-white px-3 py-2 shadow-lg">
            <p class="text-sm text-ink-soft">Tidak ada pasien yang sesuai.</p>
            <p class="mt-0.5 text-xs text-ink-muted">Jika pasien belum terdaftar, pilih "Pasien Baru".</p>
        </div>

        <p x-show="errored" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-danger-100 bg-white px-3 py-2 text-sm text-danger-700 shadow-lg">
            Gagal mencari pasien. Silakan coba lagi.
        </p>
    </div>

    <p class="text-xs text-ink-muted">
        Ketik nama pasien atau nomor RM, lalu pilih salah satu hasil pencarian.
    </p>
</div>
