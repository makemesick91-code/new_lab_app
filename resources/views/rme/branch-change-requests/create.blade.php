{{--
    FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the operator's view of today's locked
    working branch, and the only route to changing it.

    Presentation only. Every rule shown here is enforced server-side by
    DailyBranchContextService and BranchChangeApprovalService; nothing on this
    page is a boundary.
--}}
<x-settings-shell title="Cabang Kerja Hari Ini">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-ui.page-header title="Cabang Kerja Hari Ini">
            <x-slot:breadcrumb>Konteks Kerja — Kunci Harian</x-slot:breadcrumb>
            <x-slot:subtitle>
                Cabang kerja dipilih sekali setiap hari klinis. Perpindahan berikutnya
                memerlukan persetujuan Super Admin.
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

        {{-- The locked state, stated plainly. The lock icon is decorative: the
             status is carried by the text, never by colour or an icon alone. --}}
        <x-ui.card>
            <div class="space-y-1">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">
                    Cabang kerja terkunci ({{ $clinicalDate }})
                </p>
                <p class="text-lg font-semibold text-navy">
                    {{ $currentBranch?->name ?? 'Belum dipilih' }}
                    @if ($dailyContext)
                        <span aria-hidden="true">&#128274;</span>
                        <span class="sr-only">terkunci</span>
                    @endif
                </p>
                @if ($dailyContext)
                    <p class="text-sm text-ink-soft">
                        Cabang kerja terkunci untuk hari ini. Untuk pindah cabang, ajukan
                        permintaan dan tunggu persetujuan Super Admin.
                    </p>
                    @if ($dailyContext->change_count > 0)
                        <p class="text-xs text-ink-muted">
                            Sudah {{ $dailyContext->change_count }} kali dipindahkan hari ini
                            (setiap perpindahan disetujui terpisah).
                        </p>
                    @endif
                @endif
            </div>
        </x-ui.card>

        @if ($pendingRequest)
            {{-- While a request is pending the working branch does NOT move. --}}
            <x-ui.card>
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-navy">
                        {{ $pendingRequest->sourceBranch?->name }} &rarr; {{ $pendingRequest->destinationBranch?->name }}
                    </p>
                    <x-ui.alert variant="warning">
                        Status: <strong>Menunggu Persetujuan Super Admin</strong>.
                        Cabang aktif Anda tetap <strong>{{ $pendingRequest->sourceBranch?->name }}</strong>
                        sampai permintaan disetujui.
                    </x-ui.alert>
                    <p class="text-sm text-ink-soft">Alasan: {{ $pendingRequest->reason }}</p>
                    <form method="POST"
                        action="{{ route('rme.branch-change-requests.cancel', $pendingRequest) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm">
                            Batalkan Permintaan
                        </x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @elseif ($dailyContext)
            <x-ui.card>
                <form method="POST" action="{{ route('rme.branch-change-requests.store') }}" class="space-y-5">
                    @csrf
                    <p class="text-sm font-semibold text-navy">Pindah Cabang</p>

                    <div>
                        <span class="block text-sm font-medium text-navy">Cabang sekarang</span>
                        <p class="mt-1 text-sm text-ink-soft">{{ $currentBranch?->name }}</p>
                    </div>

                    <div>
                        <label for="destination_branch_id" class="block text-sm font-medium text-navy">
                            Cabang tujuan <span class="text-danger">*</span>
                        </label>
                        <select id="destination_branch_id" name="destination_branch_id" required
                            class="mt-1 block w-full rounded-lg border-hairline bg-surface text-sm text-navy focus:border-brand-500 focus:ring-brand-500">
                            <option value="">- Pilih cabang tujuan -</option>
                            @foreach ($destinations as $branch)
                                <option value="{{ $branch->id }}" @selected(old('destination_branch_id') == $branch->id)>
                                    {{ $branch->code }} — {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="reason" class="block text-sm font-medium text-navy">
                            Alasan <span class="text-danger">*</span>
                        </label>
                        <textarea id="reason" name="reason" rows="3" required minlength="10"
                            class="mt-1 block w-full rounded-lg border-hairline bg-surface text-sm text-navy focus:border-brand-500 focus:ring-brand-500"
                            placeholder="Jelaskan alasan perpindahan cabang.">{{ old('reason') }}</textarea>
                    </div>

                    <div class="flex justify-end border-t border-hairline pt-4">
                        <x-ui.button type="submit" variant="primary">Ajukan Persetujuan</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        @if ($history->isNotEmpty())
            <x-ui.card>
                <p class="mb-3 text-sm font-semibold text-navy">Riwayat Permintaan Hari Ini</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-navy-50 text-left text-ink">
                            <tr>
                                <th class="px-3 py-2 font-medium">Perpindahan</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Keputusan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($history as $item)
                                <tr>
                                    <td class="px-3 py-2 text-ink">
                                        {{ $item->sourceBranch?->name }} &rarr; {{ $item->destinationBranch?->name }}
                                    </td>
                                    <td class="px-3 py-2 text-ink-soft">{{ $item->statusLabel() }}</td>
                                    <td class="px-3 py-2 text-ink-soft">
                                        {{ $item->decidedBy?->name ?? '—' }}
                                        @if ($item->decision_note)
                                            <span class="block text-xs text-ink-muted">{{ $item->decision_note }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
