{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — "Possible existing patient".

     Shown only when the server found a STRONG match for a new registration.
     Least-disclosure summary: name, Nomor RM, branch; birth date and MASKED
     NIK/phone only for a patient inside the actor's branch scope. Parameters: $reasonField (the reason input name), $useRoute and
     $useParam (where "use this patient" points). The partial authorizes
     nothing: the target pages apply their own rules. --}}
@php
    $duplicateCandidates = session(\App\Modules\PatientMerge\Services\PatientRegistrationDuplicateCheck::SESSION_KEY, []);
    $reasonErrorKey = str_replace(['[', ']'], ['.', ''], $reasonField);
@endphp
@if ($duplicateCandidates !== [] || $errors->has($reasonErrorKey))
    <div class="rounded-lg border border-warning-100 bg-warning-50 p-4 text-sm" data-testid="registration-duplicate-warning">
        <p class="font-semibold text-warning-700">Kemungkinan pasien sudah terdaftar</p>
        @if ($duplicateCandidates !== [])
            <ul class="mt-2 space-y-2">
                @foreach ($duplicateCandidates as $candidate)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded border border-hairline bg-surface p-2">
                        <span>
                            <span class="font-medium text-navy">{{ $candidate['name'] }}</span>
                            <span class="text-ink-soft">· {{ $candidate['medical_record_number'] ?? 'Belum ada RM' }} · {{ $candidate['branch_label'] }}</span>
                            @if ($candidate['in_scope'] ?? false)
                                <span class="block text-xs text-ink-muted">Lahir {{ $candidate['date_of_birth'] ?? '—' }} · HP {{ $candidate['phone_masked'] ?? '—' }} · NIK {{ $candidate['ktp_masked'] ?? '—' }}</span>
                            @else
                                <span class="block text-xs text-ink-muted">Terdaftar di cabang lain — detail identitas tidak ditampilkan.</span>
                            @endif
                        </span>
                        <span class="flex gap-2">
                            <a href="{{ route($useRoute, [$useParam => $candidate['id']]) }}" class="text-xs font-medium text-brand-700 hover:underline">Gunakan pasien ini</a>
                            @if (($candidate['in_scope'] ?? false) && auth()->user()?->can('request_patient_merge'))
                                <a href="{{ route('patient-merge.manual.create', ['a' => $candidate['id']]) }}" class="text-xs font-medium text-brand-700 hover:underline">Review duplikat</a>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        <label class="mt-3 block text-xs font-medium text-navy" for="duplicate-override-reason">Tetap daftarkan sebagai pasien baru — alasan (min. 10 karakter)</label>
        <input id="duplicate-override-reason" type="text" name="{{ $reasonField }}" value="{{ old($reasonErrorKey) }}"
               class="mt-1 block w-full rounded-md border-hairline text-sm" placeholder="Contoh: kembar, identitas berbeda setelah dicek KTP">
        @error($reasonErrorKey)<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
    </div>
@endif
