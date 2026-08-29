{{--
    FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the Super Admin approval queue.

    Shows the whole decision context before a decision is taken: who, which role
    context, where they are now, where they want to go, why, and when they asked.
    The approval itself re-asserts every one of those bindings under a row lock,
    so what is rendered here can never become the authority.
--}}
<x-settings-shell title="Permintaan Perpindahan Cabang">
    <div class="space-y-6">
        <x-ui.page-header title="Permintaan Perpindahan Cabang">
            <x-slot:breadcrumb>Konteks Kerja — Persetujuan</x-slot:breadcrumb>
            <x-slot:subtitle>
                Hari klinis {{ $clinicalDate }}. Menyetujui permintaan langsung memindahkan
                cabang kerja pemohon untuk sisa hari ini.
            </x-slot:subtitle>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Menunggu Persetujuan</p>

            @if ($pending->isEmpty())
                <p class="text-sm text-ink-soft">Tidak ada permintaan yang menunggu persetujuan hari ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-navy-50 text-left text-ink">
                            <tr>
                                <th class="px-3 py-2 font-medium">Pengguna</th>
                                <th class="px-3 py-2 font-medium">Konteks</th>
                                <th class="px-3 py-2 font-medium">Cabang Sekarang</th>
                                <th class="px-3 py-2 font-medium">Cabang Tujuan</th>
                                <th class="px-3 py-2 font-medium">Alasan</th>
                                <th class="px-3 py-2 font-medium">Diajukan</th>
                                <th class="px-3 py-2 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($pending as $item)
                                <tr>
                                    <td class="px-3 py-2 text-ink">{{ $item->requester?->name }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->role_context }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->sourceBranch?->name }}</td>
                                    <td class="px-3 py-2 font-medium text-ink">{{ $item->destinationBranch?->name }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->reason }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->requested_at?->format('H:i') }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST"
                                                action="{{ route('rme.branch-change-requests.approve', $item) }}"
                                                onsubmit="return confirm('Setujui perpindahan {{ $item->requester?->name }} dari {{ $item->sourceBranch?->name }} ke {{ $item->destinationBranch?->name }}? Cabang kerja pemohon langsung berpindah.');">
                                                @csrf
                                                <x-ui.button type="submit" variant="success" size="sm">Setujui</x-ui.button>
                                            </form>
                                            <form method="POST"
                                                action="{{ route('rme.branch-change-requests.reject', $item) }}"
                                                onsubmit="return confirm('Tolak permintaan ini? Cabang kerja pemohon tidak berubah.');">
                                                @csrf
                                                <x-ui.button type="submit" variant="danger" size="sm">Tolak</x-ui.button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Riwayat Keputusan</p>

            @if ($decided->isEmpty())
                <p class="text-sm text-ink-soft">Belum ada keputusan tercatat.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-navy-50 text-left text-ink">
                            <tr>
                                <th class="px-3 py-2 font-medium">Hari Klinis</th>
                                <th class="px-3 py-2 font-medium">Pengguna</th>
                                <th class="px-3 py-2 font-medium">Perpindahan</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Diputuskan Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($decided as $item)
                                <tr>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->clinical_date?->toDateString() }}</td>
                                    <td class="px-3 py-2 text-ink">{{ $item->requester?->name }}</td>
                                    <td class="px-3 py-2 text-ink-soft">
                                        {{ $item->sourceBranch?->name }} &rarr; {{ $item->destinationBranch?->name }}
                                    </td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->statusLabel() }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->decidedBy?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
