{{-- Shared GET filter bar of the oversight monitoring lists. --}}
@php
    $withRoom = $withRoom ?? false;
    $withStatus = $withStatus ?? false;
    $withTreatment = $withTreatment ?? false;
@endphp
<x-ui.filter-bar :action="$action">
    <div class="w-full md:w-48">
        <x-ui.select name="branch_id" label="Cabang">
            <option value="">Semua cabang RME</option>
            @foreach ($branchOptions as $b)
                <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
            @endforeach
        </x-ui.select>
    </div>
    @if ($withRoom)
        <div class="w-full md:w-48">
            <x-ui.select name="room_id" label="Ruangan">
                <option value="">Semua ruangan dental</option>
                @foreach ($roomOptions as $room)
                    <option value="{{ $room->id }}" @selected((string) ($filters['room_id'] ?? '') === (string) $room->id)>{{ $room->branch?->code }} · {{ $room->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    @endif
    @if ($withStatus)
        <div class="w-full md:w-44">
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach (\App\Modules\ClinicOversight\Services\OversightMonitoringService::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
    @endif
    @if ($withTreatment)
        <div class="w-full md:w-48">
            <x-ui.select name="treatment_id" label="Jenis perawatan">
                <option value="">Semua</option>
                <option value="unclassified" @selected(($filters['treatment_id'] ?? null) === 'unclassified')>Lainnya / Item Tidak Terklasifikasi</option>
            </x-ui.select>
        </div>
    @endif
    <div class="w-full md:w-40">
        <x-ui.input type="date" name="date" label="Tanggal klinik" :value="$day->date" />
    </div>
    <div class="w-full md:w-56">
        <x-ui.input name="search" label="Cari pasien / No. RM / kunjungan" :value="$filters['search'] ?? ''" />
    </div>
    <x-slot:actions>
        <x-ui.button type="submit" variant="primary" size="sm">Terapkan</x-ui.button>
        <x-ui.button variant="secondary" size="sm" :href="$action">Atur Ulang</x-ui.button>
    </x-slot:actions>
</x-ui.filter-bar>
