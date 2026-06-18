<x-settings-shell title="Template Reminder WA">
    <div class="space-y-4">
        {{-- Safety notice (Sprint 41 — manual operationalization clarity) --}}
        <div class="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">
            <strong>Perhatian:</strong> Template ini hanya teks bantuan untuk operator. Operator menyalin teks secara manual ke WhatsApp,
            lalu meninjau dan mengirim sendiri. Sistem belum mengirim WhatsApp otomatis dan tidak terhubung ke API WhatsApp.
            Jangan memasukkan No. KTP / nomor identitas ke dalam isi template.
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <form method="GET" action="{{ route('settings.wa-reminder-templates.index') }}" class="flex flex-wrap items-center gap-2">
                        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari kode, nama, atau isi pesan"
                               class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <select name="trigger_type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua trigger</option>
                            @foreach ($triggerTypes as $type)
                                <option value="{{ $type }}" @selected($filters['trigger_type'] === $type)>{{ $triggerTypeLabels[$type] }}</option>
                            @endforeach
                        </select>
                        <select name="audience_type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua audiens</option>
                            @foreach ($audienceTypes as $type)
                                <option value="{{ $type }}" @selected($filters['audience_type'] === $type)>{{ $audienceTypeLabels[$type] }}</option>
                            @endforeach
                        </select>
                        <select name="is_active" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua status</option>
                            <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                            <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
                        </select>
                        <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                        @if ($filters['search'] || $filters['trigger_type'] || $filters['audience_type'] || $filters['is_active'] !== null)
                            <a href="{{ route('settings.wa-reminder-templates.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                        @endif
                    </form>
                    @can('create', \App\Modules\WaReminderTemplate\Models\WaReminderTemplate::class)
                        <a href="{{ route('settings.wa-reminder-templates.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Template</a>
                    @endcan
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2 font-medium">Kode</th>
                                <th class="px-3 py-2 font-medium">Nama</th>
                                <th class="px-3 py-2 font-medium">Trigger</th>
                                <th class="px-3 py-2 font-medium">Audiens</th>
                                <th class="px-3 py-2 font-medium">Isi Pesan (ringkas)</th>
                                <th class="px-3 py-2 font-medium">Urutan</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($templates as $template)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-gray-600">{{ $template->code }}</td>
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ $template->name }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $triggerTypeLabels[$template->trigger_type] ?? $template->trigger_type }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $audienceTypeLabels[$template->audience_type] ?? $template->audience_type }}</td>
                                    <td class="px-3 py-2 text-gray-500 max-w-xs truncate">{{ Str::limit($template->message_body, 60) }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $template->sort_order }}</td>
                                    <td class="px-3 py-2">
                                        @if ($template->is_active)
                                            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center justify-end gap-2">
                                            @can('update', $template)
                                                <a href="{{ route('settings.wa-reminder-templates.edit', $template) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                            @endcan
                                            @can('delete', $template)
                                                <form method="POST" action="{{ route('settings.wa-reminder-templates.destroy', $template) }}" onsubmit="return confirm('Hapus template reminder WA ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada template reminder WA.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div>{{ $templates->links() }}</div>
            </div>
        </div>

        {{-- Variable examples reference --}}
        <div class="rounded-md border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
            <p class="font-medium mb-1">Contoh variabel yang tersedia dalam template:</p>
            <code class="text-xs">@{{ patient_name }}, @{{ clinic_name }}, @{{ appointment_datetime }}, @{{ amount_due }}, @{{ due_date }}, @{{ service_name }}, @{{ installment_number }}, @{{ installment_amount }}</code>
        </div>
    </div>
</x-settings-shell>
