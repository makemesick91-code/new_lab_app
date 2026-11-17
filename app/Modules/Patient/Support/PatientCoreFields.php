<?php

declare(strict_types=1);

namespace App\Modules\Patient\Support;

use App\Support\Clinical\ClinicalClock;
use Illuminate\Validation\Rule;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.2 (rule 244 §5, RM PR-4; owner
 * decision 2026-11-17 "Core fields, NIK optional. A controlled exception for
 * patients without a phone. Existing patients are never blocked.").
 *
 * The ONE rule builder for the core fields of a NEW patient, shared by both
 * registration forms — Master Data → Tambah Pasien (StorePatientRequest) and
 * Kunjungan → Pasien Baru (StoreClinicVisitRequest, under `new_patient.`) — so
 * the two can never drift apart:
 *
 *  - required: gender, place of birth (≤100, single line), date of birth
 *    (1900-01-01 .. the clinical day from {@see ClinicalClock}, never a UTC
 *    today), phone OR WhatsApp, address, occupation;
 *  - NIK stays optional; when given it is exactly 16 digits ({@see NIK_RULES},
 *    declared beside each request's own `ktp_number` key so rule 184 §10's
 *    source scan keeps seeing it);
 *  - the no-phone exception: with neither number, a reason code from config
 *    `patient.no_phone_reasons` (a 10–500 character note for LAINNYA). Only an
 *    account holding {@see WITHOUT_PHONE_PERMISSION} may send it (the requests
 *    answer 403 otherwise) and PatientService re-asserts the permission and
 *    every bound for any caller.
 *
 * An EDIT never uses these rules: every field stays optional there, so a legacy
 * or incomplete patient always saves (rule 244 §5.4).
 *
 * Owner decision 2026-11-17 (rule 244 §5.5) — a legitimate registration of a
 * historical paper RM card is exempt from the core fields: StorePatientRequest
 * asks for `required: false` ONLY on the paper-card path
 * (`StorePatientRequest::isPaperCardRegistration()`). The same fields keep
 * their formats there; none is required.
 *
 * A pure rule source: no database access, no state.
 */
final class PatientCoreFields
{
    /** Rule 244 §5.3 (plan default 2) — Front Office (with Admin Klinik, its half); Super Admin through '*'. */
    public const WITHOUT_PHONE_PERMISSION = 'register_patient_without_phone';

    /** The request fields of the exception. */
    public const NO_PHONE_FIELDS = ['no_phone_reason_code', 'no_phone_reason_note'];

    /** The patient columns of the OPEN exception; written only by PatientService. */
    public const NO_PHONE_COLUMNS = ['no_phone_reason_code', 'no_phone_reason_note', 'no_phone_recorded_at'];

    /** The reason whose note is required. */
    public const NO_PHONE_REASON_OTHER = 'LAINNYA';

    public const NO_PHONE_NOTE_MIN = 10;

    public const NO_PHONE_NOTE_MAX = 500;

    public const PLACE_OF_BIRTH_MAX = 100;

    public const BIRTH_DATE_MIN = '1900-01-01';

    /** Mirrors the `gender` values every patient form has always accepted. */
    public const GENDERS = ['Male', 'Female', 'Other'];

    /** A typed NIK, when given: exactly 16 digits. */
    public const NIK_RULES = ['digits:16'];

    /** One line of text: no control character at all; invalid UTF-8 fails too (`u`). */
    public const SINGLE_LINE_PATTERN = '/^[^\x00-\x1F\x7F]*$/u';

    /** A note: tab, line feed and carriage return allowed, no other control character. */
    public const NOTE_PATTERN = '/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u';

    /**
     * The core-field rules of a new patient.
     *
     * @param  array<string, mixed>  $input  the new patient's own fields (the whole request
     *                                       for the master form, `new_patient` for a visit)
     * @param  string  $prefix  '' or 'new_patient.'
     * @param  bool  $applies  false on an existing-patient visit: the hidden Pasien Baru
     *                         panel's leftovers are excluded, never validated
     * @param  bool  $required  false only for the paper-card exemption (rule 244 §5.5): every
     *                          format below still applies to a value that is given
     * @return array<string, mixed>
     */
    public static function rules(array $input, string $prefix = '', bool $applies = true, bool $required = true): array
    {
        $key = static fn (string $field): string => $prefix.$field;
        $fields = ['gender', 'place_of_birth', 'date_of_birth', 'phone', 'whatsapp_number', 'address', 'occupation', ...self::NO_PHONE_FIELDS];

        if (! $applies) {
            return array_fill_keys(array_map($key, $fields), ['exclude']);
        }

        $presence = $required ? 'required' : 'nullable';

        return [
            $key('gender') => [$presence, 'string', Rule::in(self::GENDERS)],
            $key('place_of_birth') => self::placeOfBirthRules(required: $required),
            $key('date_of_birth') => [$presence, 'date', ...self::birthDateBounds()],
            $key('phone') => [
                'nullable', 'string', 'max:50',
                ...($required ? ['required_without_all:'.$key('whatsapp_number').','.$key('no_phone_reason_code')] : []),
            ],
            $key('whatsapp_number') => ['nullable', 'string', 'max:50'],
            $key('address') => [$presence, 'string', 'max:1000'],
            $key('occupation') => [$presence, 'string', 'max:150'],
            $key('no_phone_reason_code') => [
                'nullable', 'string',
                // The exception is for a patient WITHOUT a number: never beside one.
                Rule::prohibitedIf(self::hasNumber($input)),
                'required_with:'.$key('no_phone_reason_note'),
                Rule::in(array_keys(self::noPhoneReasons())),
            ],
            $key('no_phone_reason_note') => [
                'nullable', 'string',
                'required_if:'.$key('no_phone_reason_code').','.self::NO_PHONE_REASON_OTHER,
                'min:'.self::NO_PHONE_NOTE_MIN, 'max:'.self::NO_PHONE_NOTE_MAX,
                'regex:'.self::NOTE_PATTERN,
            ],
        ];
    }

    /**
     * The place of birth: required at a new registration, optional on an edit.
     *
     * @return array<int, string>
     */
    public static function placeOfBirthRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:'.self::PLACE_OF_BIRTH_MAX, 'regex:'.self::SINGLE_LINE_PATTERN];
    }

    /**
     * Not before 1900, not after the clinical day (CG-12: Asia/Makassar, so a
     * birth registered at 00:30 WITA is "today" while UTC is still yesterday).
     *
     * @return array<int, string>
     */
    public static function birthDateBounds(): array
    {
        return [
            'after_or_equal:'.self::BIRTH_DATE_MIN,
            'before_or_equal:'.app(ClinicalClock::class)->todayString(),
        ];
    }

    /**
     * Indonesian messages for {@see rules()} (and the NIK format), under `$prefix`.
     *
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        $messages = [
            'gender.required' => 'Jenis kelamin wajib dipilih.',
            'gender.in' => 'Jenis kelamin tidak valid.',
            'place_of_birth.required' => 'Tempat lahir wajib diisi.',
            'place_of_birth.max' => 'Tempat lahir maksimal :max karakter.',
            'place_of_birth.regex' => 'Tempat lahir berisi karakter yang tidak valid.',
            'date_of_birth.required' => 'Tanggal lahir wajib diisi.',
            'date_of_birth.date' => 'Tanggal lahir tidak valid.',
            'date_of_birth.after_or_equal' => 'Tanggal lahir tidak boleh sebelum 1 Januari 1900.',
            'date_of_birth.before_or_equal' => 'Tanggal lahir tidak boleh melewati hari ini.',
            'phone.required_without_all' => 'Nomor ponsel atau nomor WhatsApp wajib diisi.',
            'address.required' => 'Alamat wajib diisi.',
            'occupation.required' => 'Pekerjaan wajib diisi.',
            'ktp_number.digits' => 'Nomor KTP (NIK) harus 16 digit angka.',
            'no_phone_reason_code.prohibited' => 'Pengecualian tanpa nomor telepon tidak berlaku: nomor ponsel atau WhatsApp sudah diisi.',
            'no_phone_reason_code.required_with' => 'Pilih alasan pasien tanpa nomor telepon.',
            'no_phone_reason_code.in' => 'Pilih alasan pasien tanpa nomor telepon dari daftar.',
            'no_phone_reason_note.required_if' => 'Catatan wajib diisi untuk alasan Lainnya.',
            'no_phone_reason_note.min' => 'Catatan minimal :min karakter.',
            'no_phone_reason_note.max' => 'Catatan maksimal :max karakter.',
            'no_phone_reason_note.regex' => 'Catatan berisi karakter yang tidak valid.',
        ];

        $prefixed = [];

        foreach ($messages as $key => $message) {
            $prefixed[$prefix.$key] = $message;
        }

        return $prefixed;
    }

    /**
     * The configured no-phone reasons, code => Indonesian label.
     *
     * @return array<string, string>
     */
    public static function noPhoneReasons(): array
    {
        $reasons = config('patient.no_phone_reasons', []);

        return is_array($reasons) ? array_map('strval', $reasons) : [];
    }

    /**
     * Whether the fields carry a phone or a WhatsApp number.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function hasNumber(array $fields): bool
    {
        // filled() treats a whitespace-only string as blank.
        return filled($fields['phone'] ?? null) || filled($fields['whatsapp_number'] ?? null);
    }
}
