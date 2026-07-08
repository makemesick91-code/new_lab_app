<x-settings-shell title="Template Reminder WA">
    @php($hasFilters = $filters['search'] || $filters['trigger_type'] || $filters['audience_type'] || $filters['is_active'] !== null)

    {{-- Safety notice (Sprint 41 — manual operationalization clarity; WA stays manual, no auto-send). --}}
    <x-ui.alert variant="warning" title="Template manual — bukan pengiriman otomatis">
        Template ini hanya teks bantuan untuk operator. Operator menyalin teks secara manual ke WhatsApp, lalu meninjau dan
        mengirim sendiri. Sistem belum mengirim WhatsApp otomatis dan tidak terhubung ke API WhatsApp.
        Jangan memasukkan No. KTP / nomor identitas ke dalam isi template.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('settings.wa-reminder-templates.index')">
        <div class="w-full md:w-56">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari kode, nama, atau isi pesan" aria-label="Cari template" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="trigger_type" aria-label="Filter trigger">
                <option value="">Semua trigger</option>
                @foreach ($triggerTypes as $type)
                    <option value="{{ $type }}" @selected($filters['trigger_type'] === $type)>{{ $triggerTypeLabels[$type] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="audience_type" aria-label="Filter audiens">
                <option value="">Semua audiens</option>
                @foreach ($audienceTypes as $type)
                    <option value="{{ $type }}" @selected($filters['audience_type'] === $type)>{{ $audienceTypeLabels[$type] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.select name="is_active" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
            </x-ui.select>
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button href="{{ route('settings.wa-reminder-templates.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Template</h3>
            @can('create', \App\Modules\WaReminderTemplate\Models\WaReminderTemplate::class)
                <x-ui.button href="{{ route('settings.wa-reminder-templates.create') }}" size="sm">+ Tambah Template</x-ui.button>
            @endcan
        </div>

        @if ($templates->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada template reminder WA" description="Tambahkan template teks bantuan untuk operator.">
                    @can('create', \App\Modules\WaReminderTemplate\Models\WaReminderTemplate::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.wa-reminder-templates.create') }}" size="sm">+ Tambah Template</x-ui.button>
                        </x-slot>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Kode</th>
                        <th class="px-4 py-3 font-semibold">Nama</th>
                        <th class="px-4 py-3 font-semibold">Trigger</th>
                        <th class="px-4 py-3 font-semibold">Audiens</th>
                        <th class="px-4 py-3 font-semibold">Isi Pesan (ringkas)</th>
                        <th class="px-4 py-3 font-semibold">Urutan</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($templates as $template)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 font-mono text-ink-soft">{{ $template->code }}</td>
                            <td class="px-4 py-3 font-medium text-navy">{{ $template->name }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $triggerTypeLabels[$template->trigger_type] ?? $template->trigger_type }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $audienceTypeLabels[$template->audience_type] ?? $template->audience_type }}</td>
                            <td class="px-4 py-3 max-w-xs truncate text-ink-muted">{{ Str::limit($template->message_body, 60) }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $template->sort_order }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$template->is_active ? 'success' : 'neutral'">{{ $template->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $template)
                                        <x-ui.button href="{{ route('settings.wa-reminder-templates.edit', $template) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $template)
                                        <form method="POST" action="{{ route('settings.wa-reminder-templates.destroy', $template) }}" onsubmit="return confirm('Hapus template reminder WA ini?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $templates->links() }}</div>

    {{-- Variable examples reference --}}
    <x-ui.alert variant="info" title="Contoh variabel yang tersedia dalam template">
        <code class="text-xs">@{{ patient_name }}, @{{ clinic_name }}, @{{ appointment_datetime }}, @{{ amount_due }}, @{{ due_date }}, @{{ service_name }}, @{{ installment_number }}, @{{ installment_amount }}</code>
    </x-ui.alert>
</x-settings-shell>
