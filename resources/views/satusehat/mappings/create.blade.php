<x-settings-shell title="Draft Mapping SATUSEHAT">
    <x-ui.page-header title="Draft Mapping SATUSEHAT" subtitle="Isi salah satu: ID entitas lokal atau kode lokal.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('satusehat.mappings.index')">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form method="POST" action="{{ route('satusehat.mappings.store') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.select label="Lingkungan" name="environment" required>
                @foreach ($environments as $env)
                    <option value="{{ $env }}" @selected(old('environment') === $env)>{{ $env }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Resource target" name="target_resource_type" required>
                @foreach (['Encounter', 'Condition', 'Procedure', 'Observation', 'Medication', 'Patient', 'Practitioner', 'Organization', 'Location'] as $rt)
                    <option value="{{ $rt }}" @selected(old('target_resource_type') === $rt)>{{ $rt }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Tipe entitas lokal (mis. treatment)" name="local_entity_type" :value="old('local_entity_type')" required />
            <x-ui.input label="ID entitas lokal" name="local_entity_id" type="number" :value="old('local_entity_id')" />
            <x-ui.input label="Kode lokal" name="local_code" :value="old('local_code')" />
            <x-ui.input label="Sistem terminologi" name="terminology_system" :value="old('terminology_system')" />
            <x-ui.input label="Kode target" name="target_code" :value="old('target_code')" />
            <x-ui.input label="Tampilan target" name="target_display" :value="old('target_display')" />
            <x-ui.input label="Path FHIR" name="target_path" :value="old('target_path')" />
            <x-ui.input label="Berlaku sejak" name="effective_date" type="date" :value="old('effective_date')" />
            <x-ui.textarea label="Catatan" name="notes">{{ old('notes') }}</x-ui.textarea>
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Simpan Draft</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
