@props([
    'name' => 'patient_id',
    'patients' => collect(),
    'selected' => null,
    'placeholder' => '- Pilih pasien terdaftar -',
    'searchPlaceholder' => 'Cari nama atau nomor RM…',
    'label' => null,
    'selectClass' => 'mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500',
    'showSearch' => true,
])

@php
    $selectedValue = old($name, $selected);
@endphp

<div data-patient-search-select {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    @endif

    @if ($showSearch)
        <input
            type="search"
            autocomplete="off"
            dusk="patient-search"
            data-patient-search-input
            placeholder="{{ $searchPlaceholder }}"
            class="block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
        />
    @endif

    <select
        name="{{ $name }}"
        dusk="patient-select"
        data-patient-select
        class="{{ $selectClass }}"
    >
        <option value="">{{ $placeholder }}</option>
        @foreach ($patients as $patient)
            <option
                value="{{ $patient->id }}"
                @selected((string) $selectedValue === (string) $patient->id)
            >{{ $patient->selectorLabel() }} ({{ $patient->branchLabel() }}){{ $patient->phone ? ' · '.$patient->phone : '' }}</option>
        @endforeach
    </select>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('[data-patient-search-select]').forEach((wrapper) => {
                    const searchInput = wrapper.querySelector('[data-patient-search-input]');
                    const select = wrapper.querySelector('[data-patient-select]');

                    if (! select) {
                        return;
                    }

                    const filterOptions = () => {
                        const term = (searchInput?.value || '').trim().toLowerCase();

                        select.querySelectorAll('option').forEach((option) => {
                            if (option.value === '') {
                                option.hidden = false;

                                return;
                            }

                            const haystack = option.textContent.toLowerCase();
                            option.hidden = term !== '' && ! haystack.includes(term);
                        });
                    };

                    searchInput?.addEventListener('input', filterOptions);
                    searchInput?.addEventListener('search', filterOptions);
                });
            });
        </script>
    @endpush
@endonce
