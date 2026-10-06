{{-- Shared console filters. $fields lists which inputs this page offers; every
     value is validated server-side by ObservabilityFilterRequest. --}}
<x-ui.filter-bar :action="$action">
    <x-ui.input type="date" name="from" label="Dari (WITA)" :value="request('from')" />
    <x-ui.input type="date" name="to" label="Sampai (WITA)" :value="request('to')" />
    @if (in_array('status', $fields, true))
        <x-ui.select name="status" label="Status">
            <option value="">Semua</option>
            @foreach (config('observability_console.error_statuses') as $code)
                <option value="{{ $code }}" @selected((string) request('status') === (string) $code)>{{ $code }}</option>
            @endforeach
        </x-ui.select>
    @endif
    @if (in_array('user', $fields, true))
        <x-ui.select name="user_id" label="User">
            <option value="">Semua</option>
            @foreach ($options['users'] as $id => $name)
                <option value="{{ $id }}" @selected((string) request('user_id') === (string) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.select>
    @endif
    @if (in_array('branch', $fields, true))
        <x-ui.select name="branch_id" label="Cabang">
            <option value="">Semua</option>
            @foreach ($options['branches'] as $id => $code)
                <option value="{{ $id }}" @selected((string) request('branch_id') === (string) $id)>{{ $code }}</option>
            @endforeach
        </x-ui.select>
    @endif
    @if (in_array('category', $fields, true))
        <x-ui.select name="category" label="Kategori">
            <option value="">Semua (≥ WATCH)</option>
            @foreach (\App\Modules\Observability\Support\LatencyCategory::ALL as $cat)
                @continue($cat === 'NORMAL')
                <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ $cat }}</option>
            @endforeach
        </x-ui.select>
    @endif
    @if (in_array('min_ms', $fields, true))
        <x-ui.input type="number" name="min_ms" label="Durasi min (ms)" min="0" :value="request('min_ms')" />
    @endif
    <x-ui.input name="route" label="Route (awalan)" placeholder="rme.visits" :value="request('route')" />
    <x-slot name="actions">
        <x-ui.button type="submit">Terapkan</x-ui.button>
        <x-ui.button variant="secondary" :href="$action">Atur Ulang</x-ui.button>
    </x-slot>
</x-ui.filter-bar>
