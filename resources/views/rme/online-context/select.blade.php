<x-settings-shell title="Konteks Kerja Online">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-ui.page-header
            title="Atur Konteks Kerja Anda">
            <x-slot:breadcrumb>Sprint 66 — Kehadiran Online</x-slot:breadcrumb>
            <x-slot:subtitle>
                @if ($requiresDoctor)
                    Pilih cabang RME dan ruangan perawatan agar status dokter Anda aktif (online).
                @elseif ($requiresAdmin)
                    Pilih cabang RME sebagai konteks kerja admin klinik Anda.
                @endif
            </x-slot:subtitle>
        </x-ui.page-header>

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @if ($requiresDoctor)
            @if ($linkedDoctor === null)
                <x-ui.card>
                    <div class="space-y-2 text-sm text-warning-700">
                        <p class="font-medium">Akun belum terhubung ke data master dokter</p>
                        <p>Hubungi admin klinik untuk menghubungkan akun login Anda dengan data dokter di pengaturan master data.</p>
                    </div>
                </x-ui.card>
            @elseif ($doctorAllowedBranches->isEmpty())
                <x-ui.card>
                    <div class="space-y-2 text-sm text-warning-700">
                        <p class="font-medium">Dokter belum memiliki Cabang Praktik</p>
                        <p>Hubungi Admin Klinik untuk menetapkan Cabang Praktik yang Diizinkan pada data master dokter Anda.</p>
                    </div>
                </x-ui.card>
            @else
                <x-ui.card>
                    <form method="POST" action="{{ route('rme.online-context.doctor') }}" class="space-y-5"
                        x-data="onlineContextDoctorForm(@js(collect($roomsByBranch->all())->only($doctorAllowedBranches->pluck('id')->all())->map(fn ($rooms, $branchId) => ['branch_id' => (int) $branchId, 'rooms' => $rooms->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()])->values()))">
                        @csrf
                        <div>
                            <p class="text-sm text-ink-soft">Dokter: <span class="font-medium text-navy">{{ $linkedDoctor->name }}</span></p>
                        </div>
                        <div>
                            <label for="doctor_branch_id" class="block text-sm font-medium text-navy">Cabang Praktik <span class="text-danger">*</span></label>
                            <select id="doctor_branch_id" name="branch_id" required x-model="branchId" @change="syncRooms()"
                                class="mt-1 block w-full rounded-lg border-hairline bg-surface text-sm text-navy focus:border-brand-500 focus:ring-brand-500">
                                <option value="">- Pilih cabang praktik -</option>
                                @foreach ($doctorAllowedBranches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->code }} — {{ $branch->name }}</option>
                                @endforeach
                            </select>
                            @error('branch_id')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="doctor_clinic_room_id" class="block text-sm font-medium text-navy">Ruangan Perawatan <span class="text-danger">*</span></label>
                            <select id="doctor_clinic_room_id" name="clinic_room_id" required x-model="roomId"
                                class="mt-1 block w-full rounded-lg border-hairline bg-surface text-sm text-navy focus:border-brand-500 focus:ring-brand-500"
                                :disabled="rooms.length === 0">
                                <option value="">- Pilih ruangan -</option>
                                <template x-for="room in rooms" :key="room.id">
                                    <option :value="room.id" x-text="room.name"></option>
                                </template>
                            </select>
                            @error('clinic_room_id')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            <p x-show="branchId && rooms.length === 0" class="mt-2 text-xs text-warning-700">
                                Belum ada ruangan aktif di cabang ini. Hubungi admin klinik.
                            </p>
                        </div>
                        <div class="flex justify-end border-t border-hairline pt-4">
                            <x-ui.button type="submit" variant="primary" ::disabled="!branchId || !roomId">Mulai Online</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        @endif

        @if ($requiresAdmin)
            <x-ui.card>
                <form method="POST" action="{{ route('rme.online-context.admin-clinic') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="admin_branch_id" class="block text-sm font-medium text-navy">Cabang RME <span class="text-danger">*</span></label>
                        <select id="admin_branch_id" name="branch_id" required
                            class="mt-1 block w-full rounded-lg border-hairline bg-surface text-sm text-navy focus:border-brand-500 focus:ring-brand-500">
                            <option value="">- Pilih cabang RME -</option>
                            @foreach ($rmeBranches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('branch_id', $currentContext?->branch_id) == $branch->id)>
                                    {{ $branch->code }} — {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end border-t border-hairline pt-4">
                        <x-ui.button type="submit" variant="primary">Mulai Bertugas</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    </div>

    @push('scripts')
        <script>
            function onlineContextDoctorForm(branchRooms) {
                return {
                    branchId: '',
                    roomId: '',
                    rooms: [],
                    branchRooms,
                    syncRooms() {
                        const match = this.branchRooms.find((entry) => String(entry.branch_id) === String(this.branchId));
                        this.rooms = match ? match.rooms : [];
                        this.roomId = '';
                    },
                };
            }
        </script>
    @endpush
</x-settings-shell>
