<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Safe, auditable, rollback-safe batch import of legacy patient master data via
 * a staging + preview + commit workflow. Nothing touches mst_patients until an
 * explicit Commit. KTP/NIK is never rendered in full. No visits / medical
 * records / invoices / consent / odontogram rows are ever created here.
 *
 * CSV parsing mirrors Inventory\Services\ProductImportService (native fgetcsv,
 * BOM strip, header assert, blank-row skip) — no maatwebsite/excel dependency.
 */
class LegacyPatientImportService
{
    /**
     * Canonical column keys in sheet order. Header assertion is position based
     * (like ProductImport) after lowercasing/trimming the human labels.
     *
     * @var array<int, array{key: string, label: string}>
     */
    public const COLUMNS = [
        ['key' => 'no', 'label' => 'No.'],
        ['key' => 'legacy_patient_id', 'label' => 'ID Pasien'],
        ['key' => 'ktp_number', 'label' => 'Nomor KTP'],
        ['key' => 'branch', 'label' => 'Cabang'],
        ['key' => 'room', 'label' => 'Ruangan'],
        ['key' => 'timestamp', 'label' => 'Timestamp'],
        ['key' => 'manual_rm_number', 'label' => 'Nomor RM Manual'],
        ['key' => 'doctor', 'label' => 'Dokter'],
        ['key' => 'name', 'label' => 'Nama Pasien'],
        ['key' => 'phone', 'label' => 'Ponsel'],
        ['key' => 'whatsapp_number', 'label' => 'Nomor WA'],
        ['key' => 'email', 'label' => 'E-Mail'],
        ['key' => 'gender', 'label' => 'Jenis Kelamin'],
        ['key' => 'date_of_birth', 'label' => 'Tanggal Lahir'],
        ['key' => 'age', 'label' => 'Umur'],
        ['key' => 'address', 'label' => 'Alamat Lengkap'],
        ['key' => 'occupation', 'label' => 'Pekerjaan'],
        ['key' => 'initial_treatment', 'label' => 'Tindakan Awal'],
        ['key' => 'chief_complaint', 'label' => 'Keluhan Utama'],
        ['key' => 'consent_doctor', 'label' => 'TTD Surat Persetujuan Tindakan - Dokter'],
        ['key' => 'consent_patient', 'label' => 'TTD Surat Persetujuan Tindakan - Pasien'],
    ];

    public function __construct(
        private readonly PatientMedicalRecordNumberService $rmNumbers,
        private readonly PatientDataCompletenessService $completeness,
    ) {}

    /**
     * @return array{header: array<int, string>, example: array<int, string>}
     */
    public function templateRows(): array
    {
        return [
            'header' => array_map(static fn ($c) => $c['label'], self::COLUMNS),
            'example' => [
                '1', 'LEG-0001', '3201010101010001', 'TKM1', 'Ruang 1',
                '2024-01-15 09:30:00', '0001', 'drg. Contoh', 'Budi Santoso',
                '081200000001', '081200000001', 'budi@example.com', 'Laki-laki',
                '1990-05-20', '34', 'Jl. Contoh No. 1', 'Wiraswasta',
                'Pembersihan karang gigi', 'Gigi ngilu', 'Ya', 'Ya',
            ],
        ];
    }

    public function templateFilename(): string
    {
        return 'legacy-patient-import-template.csv';
    }

    /**
     * Parse the uploaded CSV into a staging batch + rows. Never writes to
     * mst_patients. Throws InvalidArgumentException on a bad header.
     */
    public function parseAndStage(UploadedFile $file, ?int $uploadedBy): LegacyPatientImportBatch
    {
        $rows = $this->parseCsv($file);

        $contents = (string) file_get_contents($file->getRealPath());
        $storedPath = $file->store('legacy-patient-imports', 'local');

        $batch = LegacyPatientImportBatch::create([
            'uuid' => (string) Str::uuid(),
            'uploaded_by' => $uploadedBy,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => hash('sha256', $contents),
            'status' => LegacyPatientImportBatch::STATUS_UPLOADED,
            'total_rows' => count($rows),
        ]);

        $context = $this->buildLookupContext();
        $seenRm = [];
        $seenKtp = [];
        $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];
        $errorSummary = [];

        foreach ($rows as $row) {
            $mapped = $this->validateAndMapRow($row['values'], $context, $seenRm, $seenKtp);
            $counts[$mapped['status']]++;

            foreach ($mapped['errors'] as $message) {
                $errorSummary[] = ['row' => $row['row_number'], 'severity' => 'error', 'message' => $message];
            }

            LegacyPatientImportRow::create(array_merge($mapped['attributes'], [
                'batch_id' => $batch->id,
                'row_number' => $row['row_number'],
                'raw_payload' => $row['values'],
            ]));
        }

        $batch->update([
            'status' => LegacyPatientImportBatch::STATUS_VALIDATED,
            'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['warning'],
            'error_rows' => $counts['error'],
            'error_summary' => $errorSummary !== [] ? $errorSummary : null,
        ]);

        return $batch->refresh();
    }

    /**
     * Validate + normalize + map a single raw row. Returns the staging
     * attributes, the resolved row status, and the list of error messages.
     *
     * @param  array<string, string>  $values
     * @param  array{branchByCode: Collection, branchByName: Collection, doctorMap: Collection, roomsByBranch: Collection}  $context
     * @param  array<int, string>  $seenRm
     * @param  array<int, string>  $seenKtp
     * @return array{status: string, errors: array<int, string>, attributes: array<string, mixed>}
     */
    public function validateAndMapRow(array $values, array $context, array &$seenRm, array &$seenKtp): array
    {
        $errors = [];
        $warnings = [];

        $name = trim($values['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Nama Pasien wajib diisi.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Nama Pasien maksimal 150 karakter.';
        }

        // --- Branch (required, strict) ---
        $branchLabel = trim($values['branch'] ?? '');
        $branch = null;
        if ($branchLabel === '') {
            $errors[] = 'Cabang wajib diisi.';
        } else {
            $branch = $context['branchByCode']->get(Str::upper($branchLabel))
                ?? $context['branchByName']->get(Str::lower($branchLabel));
            if (! $branch) {
                $errors[] = 'Cabang tidak ditemukan atau bukan cabang RME aktif (MAIN dikecualikan).';
            }
        }

        // --- Manual RM (required) ---
        $manualRm = $this->digitsOnly($values['manual_rm_number'] ?? '');
        if ($manualRm === '') {
            $errors[] = 'Nomor RM Manual wajib diisi dan hanya boleh berisi angka.';
        } elseif (mb_strlen($manualRm) > 50) {
            $errors[] = 'Nomor RM Manual maksimal 50 digit.';
        }

        // --- Timestamp / registration date (required; drives RM year) ---
        $registeredAt = $this->parseDate($values['timestamp'] ?? '');
        if ($registeredAt === null) {
            $errors[] = 'Timestamp/Tanggal Daftar wajib diisi dan harus berupa tanggal valid.';
        }

        // --- Composed RM (unique incl. trashed + in-file) ---
        $composedRm = null;
        if ($branch && $manualRm !== '' && $registeredAt !== null) {
            $composedRm = $this->rmNumbers->composeForRegistration($branch->code, $registeredAt, $manualRm);

            if (in_array($composedRm, $seenRm, true)) {
                $errors[] = "Nomor RM final {$composedRm} duplikat di dalam file.";
            } elseif ($this->rmNumbers->exists($composedRm)) {
                $errors[] = "Nomor RM final {$composedRm} sudah digunakan pasien lain.";
            }
        }

        // --- KTP (optional; unique incl. trashed + in-file) ---
        $ktp = $this->digitsOnly($values['ktp_number'] ?? '');
        if ($ktp !== '') {
            if (mb_strlen($ktp) > 16) {
                $errors[] = 'Nomor KTP maksimal 16 digit.';
            } elseif (in_array($ktp, $seenKtp, true)) {
                $errors[] = 'Nomor KTP duplikat di dalam file.';
            } elseif (Patient::withTrashed()->where('ktp_number', $ktp)->exists()) {
                $errors[] = 'Nomor KTP sudah terdaftar pada pasien lain.';
            }
        }

        // --- Email (optional) ---
        $email = Str::lower(trim($values['email'] ?? ''));
        if ($email !== '') {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Format E-Mail tidak valid.';
            } elseif (mb_strlen($email) > 150) {
                $errors[] = 'E-Mail maksimal 150 karakter.';
            }
        } else {
            $email = null;
        }

        // --- Date of birth (optional; not future) ---
        $dob = null;
        $dobRaw = trim($values['date_of_birth'] ?? '');
        if ($dobRaw !== '') {
            $dob = $this->parseDate($dobRaw);
            if ($dob === null) {
                $errors[] = 'Tanggal Lahir tidak valid.';
            } elseif ($dob->isFuture()) {
                $errors[] = 'Tanggal Lahir tidak boleh di masa depan.';
            }
        }

        // --- Gender map ---
        $gender = $this->mapGender($values['gender'] ?? '');
        if ($gender === false) {
            $warnings[] = 'Jenis Kelamin tidak dikenali, disimpan sebagai Other.';
            $gender = 'Other';
        }

        // --- Doctor (optional, lenient) ---
        $doctorLabel = trim($values['doctor'] ?? '');
        $doctorId = null;
        if ($doctorLabel !== '') {
            $doctor = $context['doctorMap']->get(Str::lower($doctorLabel));
            if ($doctor) {
                $doctorId = $doctor->id;
            } else {
                $warnings[] = 'Dokter tidak ditemukan di master; disimpan tanpa dokter.';
            }
        }

        // --- Room (advisory only) ---
        $roomLabel = trim($values['room'] ?? '');
        $roomId = null;
        if ($roomLabel !== '' && $branch) {
            $room = $context['roomsByBranch']->get($branch->id.'|'.Str::lower($roomLabel));
            if ($room) {
                $roomId = $room->id;
            }
        }

        // --- Length caps on optional contact / free-text ---
        $phone = trim($values['phone'] ?? '');
        if (mb_strlen($phone) > 50) {
            $errors[] = 'Ponsel maksimal 50 karakter.';
        }
        $wa = $this->normalizePhone($values['whatsapp_number'] ?? '');
        if (mb_strlen($wa) > 50) {
            $errors[] = 'Nomor WA maksimal 50 karakter.';
        }
        $address = trim($values['address'] ?? '');
        if (mb_strlen($address) > 1000) {
            $errors[] = 'Alamat Lengkap maksimal 1000 karakter.';
        }
        $occupation = trim($values['occupation'] ?? '');
        if (mb_strlen($occupation) > 150) {
            $errors[] = 'Pekerjaan maksimal 150 karakter.';
        }

        // --- Age vs DOB cross-check (warning only) ---
        $ageRaw = trim($values['age'] ?? '');
        if ($ageRaw !== '' && $dob !== null && is_numeric($ageRaw)) {
            if (abs($dob->age - (int) $ageRaw) > 1) {
                $warnings[] = 'Umur tidak sesuai dengan Tanggal Lahir.';
            }
        }

        // --- Soft duplicate: name + DOB against existing active patient ---
        if ($name !== '' && $dob !== null) {
            $exists = Patient::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->whereDate('date_of_birth', $dob->toDateString())
                ->exists();
            if ($exists) {
                $warnings[] = 'Kemungkinan duplikat: nama + tanggal lahir cocok dengan pasien yang sudah ada.';
            }
        }

        // Track identity keys for in-file duplicate detection on later rows.
        // (The first occurrence passes; a second identical RM/KTP is blocked.)
        if ($composedRm !== null) {
            $seenRm[] = $composedRm;
        }
        if ($ktp !== '' && mb_strlen($ktp) <= 16) {
            $seenKtp[] = $ktp;
        }

        $status = $errors !== []
            ? LegacyPatientImportRow::STATUS_ERROR
            : ($warnings !== [] ? LegacyPatientImportRow::STATUS_WARNING : LegacyPatientImportRow::STATUS_VALID);

        $normalized = [
            'name' => $name !== '' ? $name : null,
            'ktp_number' => $ktp !== '' ? $ktp : null,
            'gender' => $gender ?: null,
            'date_of_birth' => $dob?->toDateString(),
            'phone' => $phone !== '' ? $phone : null,
            'whatsapp_number' => $wa !== '' ? $wa : null,
            'email' => $email,
            'address' => $address !== '' ? $address : null,
            'occupation' => $occupation !== '' ? $occupation : null,
            'registered_at' => $registeredAt?->toDateString(),
            'manual_rm_number' => $manualRm !== '' ? $manualRm : null,
            'medical_record_number' => $composedRm,
            'branch_id' => $branch?->id,
            'doctor_id' => $doctorId,
        ];

        return [
            'status' => $status,
            'errors' => $errors,
            'attributes' => [
                'status' => $status,
                'errors' => $errors !== [] ? $errors : null,
                'warnings' => $warnings !== [] ? $warnings : null,
                'normalized_payload' => $normalized,
                'generated_medical_record_number' => $composedRm,
                'matched_branch_id' => $branch?->id,
                'matched_room_id' => $roomId,
                'matched_doctor_id' => $doctorId,
                'ktp_masked' => $ktp !== '' ? $this->completeness->maskKtp($ktp) : null,
                'patient_name' => $name !== '' ? $name : null,
                'phone' => $phone !== '' ? $phone : null,
                'wa_number' => $wa !== '' ? $wa : null,
                'email' => $email,
                'gender' => $gender ?: null,
                'birth_date' => $dob?->toDateString(),
                'address' => $address !== '' ? $address : null,
                'occupation' => $occupation !== '' ? $occupation : null,
                'manual_rm_number' => $manualRm !== '' ? $manualRm : null,
                'legacy_patient_id' => ($lp = trim($values['legacy_patient_id'] ?? '')) !== '' ? $lp : null,
                'legacy_timestamp' => ($lt = trim($values['timestamp'] ?? '')) !== '' ? $lt : null,
                'advisory_initial_treatment' => ($v = trim($values['initial_treatment'] ?? '')) !== '' ? $v : null,
                'advisory_chief_complaint' => ($v = trim($values['chief_complaint'] ?? '')) !== '' ? $v : null,
                'advisory_doctor_signature' => ($v = trim($values['consent_doctor'] ?? '')) !== '' ? $v : null,
                'advisory_patient_signature' => ($v = trim($values['consent_patient'] ?? '')) !== '' ? $v : null,
            ],
        ];
    }

    /**
     * Commit the committable (valid|warning) rows of a validated batch into
     * mst_patients inside a single transaction. Idempotent: a non-validated
     * batch is a no-op. Re-checks RM/KTP uniqueness under lock to avoid a
     * preview-to-commit race; a now-duplicate row is skipped, not overwritten.
     */
    public function commit(LegacyPatientImportBatch $batch, ?int $committedBy): LegacyPatientImportBatch
    {
        if ($batch->status !== LegacyPatientImportBatch::STATUS_VALIDATED) {
            return $batch;
        }

        DB::transaction(function () use ($batch, $committedBy): void {
            $batch->update(['status' => LegacyPatientImportBatch::STATUS_COMMITTING]);

            $committed = 0;

            $rows = LegacyPatientImportRow::query()
                ->where('batch_id', $batch->id)
                ->whereIn('status', [LegacyPatientImportRow::STATUS_VALID, LegacyPatientImportRow::STATUS_WARNING])
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $data = $row->normalized_payload ?? [];
                $rm = $data['medical_record_number'] ?? null;
                $ktp = $data['ktp_number'] ?? null;

                if (! $rm) {
                    $row->update(['status' => LegacyPatientImportRow::STATUS_SKIPPED]);

                    continue;
                }

                $rmTaken = Patient::withTrashed()->where('medical_record_number', $rm)->lockForUpdate()->exists();
                $ktpTaken = $ktp ? Patient::withTrashed()->where('ktp_number', $ktp)->lockForUpdate()->exists() : false;

                if ($rmTaken || $ktpTaken) {
                    $warnings = $row->warnings ?? [];
                    $warnings[] = 'Dilewati saat commit: RM/KTP menjadi duplikat (kemungkinan pendaftaran bersamaan).';
                    $row->update([
                        'status' => LegacyPatientImportRow::STATUS_SKIPPED,
                        'warnings' => $warnings,
                    ]);

                    continue;
                }

                $patient = Patient::create([
                    'clinic_id' => null,
                    'doctor_id' => $data['doctor_id'] ?? null,
                    'branch_id' => $data['branch_id'] ?? null,
                    'medical_record_number' => $rm,
                    'registered_at' => $data['registered_at'] ?? null,
                    'manual_rm_number' => $data['manual_rm_number'] ?? null,
                    'ktp_number' => $ktp,
                    'name' => $data['name'],
                    'gender' => $data['gender'] ?? null,
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'whatsapp_number' => $data['whatsapp_number'] ?? null,
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'is_active' => true,
                    'import_batch_id' => $batch->id,
                ]);

                $row->update([
                    'status' => LegacyPatientImportRow::STATUS_COMMITTED,
                    'committed_patient_id' => $patient->id,
                ]);
                $committed++;
            }

            $batch->update([
                'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
                'committed_rows' => $committed,
                'committed_by' => $committedBy,
                'committed_at' => now(),
            ]);
        });

        return $batch->refresh();
    }

    /**
     * Soft-delete all patients created by a committed batch. Blocked when any
     * imported patient already has a downstream visit / medical record (cannot
     * silently remove a patient who started a real RME workflow).
     *
     * @throws RuntimeException
     */
    public function rollback(LegacyPatientImportBatch $batch, ?int $rolledBackBy): LegacyPatientImportBatch
    {
        if (! $batch->isCommitted()) {
            throw new RuntimeException('Hanya batch yang sudah di-commit yang dapat di-rollback.');
        }

        $patientIds = LegacyPatientImportRow::query()
            ->where('batch_id', $batch->id)
            ->whereNotNull('committed_patient_id')
            ->pluck('committed_patient_id')
            ->all();

        if ($this->hasDownstreamRecords($patientIds)) {
            throw new RuntimeException('Rollback ditolak: sebagian pasien sudah memiliki kunjungan/rekam medis. Tangani manual.');
        }

        DB::transaction(function () use ($batch, $patientIds, $rolledBackBy): void {
            Patient::query()
                ->where('import_batch_id', $batch->id)
                ->whereIn('id', $patientIds)
                ->get()
                ->each->delete();

            LegacyPatientImportRow::query()
                ->where('batch_id', $batch->id)
                ->where('status', LegacyPatientImportRow::STATUS_COMMITTED)
                ->update(['status' => LegacyPatientImportRow::STATUS_ROLLED_BACK]);

            $batch->update([
                'status' => LegacyPatientImportBatch::STATUS_ROLLED_BACK,
                'rolled_back_by' => $rolledBackBy,
                'rolled_back_at' => now(),
            ]);
        });

        return $batch->refresh();
    }

    /**
     * Discard a pre-commit batch (soft-delete batch + staging rows). mst_patients
     * is never touched for a discardable batch.
     */
    public function discard(LegacyPatientImportBatch $batch): void
    {
        if (in_array($batch->status, [
            LegacyPatientImportBatch::STATUS_COMMITTED,
            LegacyPatientImportBatch::STATUS_ROLLED_BACK,
        ], true)) {
            throw new RuntimeException('Batch yang sudah di-commit tidak dapat dibuang; gunakan rollback.');
        }

        $batch->delete();
    }

    /**
     * Build the flat error/warning report rows for CSV download (one line per
     * message; KTP masked).
     *
     * @return array<int, array<int, string>>
     */
    public function errorReportRows(LegacyPatientImportBatch $batch): array
    {
        $lines = [];

        $rows = LegacyPatientImportRow::query()
            ->where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->get();

        foreach ($rows as $row) {
            $branch = $row->matched_branch_id ? (Branch::find($row->matched_branch_id)?->code ?? '') : '';
            $messages = [];
            foreach (($row->errors ?? []) as $m) {
                $messages[] = ['error', $m];
            }
            foreach (($row->warnings ?? []) as $m) {
                $messages[] = ['warning', $m];
            }

            if ($messages === []) {
                continue;
            }

            foreach ($messages as [$severity, $message]) {
                $lines[] = [
                    (string) $row->row_number,
                    (string) ($row->patient_name ?? ''),
                    $branch,
                    (string) ($row->generated_medical_record_number ?? ''),
                    (string) ($row->ktp_masked ?? ''),
                    $row->status,
                    $severity,
                    $message,
                ];
            }
        }

        return $lines;
    }

    public function errorReportHeader(): array
    {
        return ['row', 'name', 'branch', 'composed_rm', 'ktp_masked', 'row_status', 'severity', 'message'];
    }

    /**
     * @param  array<int, int>  $patientIds
     */
    private function hasDownstreamRecords(array $patientIds): bool
    {
        if ($patientIds === []) {
            return false;
        }

        if (DB::table('trx_clinic_visits')->whereIn('patient_id', $patientIds)->exists()) {
            return true;
        }

        if (Schema::hasColumn('trx_medical_records', 'patient_id')
            && DB::table('trx_medical_records')->whereIn('patient_id', $patientIds)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * @return array{branchByCode: Collection, branchByName: Collection, doctorMap: Collection, roomsByBranch: Collection}
     */
    private function buildLookupContext(): array
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->where('is_rme_enabled', true)
            ->where('code', '!=', Branch::MAIN_CODE)
            ->get();

        $doctors = Doctor::query()->where('is_active', true)->get();

        $rooms = ClinicRoom::query()->where('status', ClinicRoom::STATUS_ACTIVE)->get();

        $doctorMap = collect();
        foreach ($doctors as $doctor) {
            if ($doctor->code) {
                $doctorMap->put(Str::lower(trim($doctor->code)), $doctor);
            }
            $doctorMap->put(Str::lower(trim($doctor->name)), $doctor);
        }

        $roomsByBranch = collect();
        foreach ($rooms as $room) {
            if ($room->code) {
                $roomsByBranch->put($room->branch_id.'|'.Str::lower(trim($room->code)), $room);
            }
            $roomsByBranch->put($room->branch_id.'|'.Str::lower(trim($room->name)), $room);
        }

        return [
            'branchByCode' => $branches->keyBy(static fn ($b) => Str::upper(trim($b->code))),
            'branchByName' => $branches->keyBy(static fn ($b) => Str::lower(trim($b->name))),
            'doctorMap' => $doctorMap,
            'roomsByBranch' => $roomsByBranch,
        ];
    }

    /**
     * @return array<int, array{row_number: int, values: array<string, string>}>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);

            return [];
        }

        $this->assertExpectedHeader($headerRow);

        $rows = [];
        $rowNumber = 1;
        $keys = array_map(static fn ($c) => $c['key'], self::COLUMNS);

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isBlankRow($data)) {
                continue;
            }

            $values = [];
            foreach ($keys as $index => $key) {
                $values[$key] = trim((string) ($data[$index] ?? ''));
            }

            $rows[] = ['row_number' => $rowNumber, 'values' => $values];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $headerRow
     */
    private function assertExpectedHeader(array $headerRow): void
    {
        if (isset($headerRow[0])) {
            $headerRow[0] = ltrim((string) $headerRow[0], "\xEF\xBB\xBF");
        }

        $normalized = array_map(static fn ($v) => Str::lower(trim((string) $v)), $headerRow);
        $expected = array_map(static fn ($c) => Str::lower($c['label']), self::COLUMNS);

        if ($normalized !== $expected) {
            throw new \InvalidArgumentException('Header CSV tidak sesuai template legacy pasien. Unduh template sistem.');
        }
    }

    /**
     * @param  array<int, string|null>  $data
     */
    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    private function normalizePhone(string $value): string
    {
        $value = trim($value);

        return preg_replace('/[^0-9+]/', '', $value) ?? '';
    }

    /**
     * @return string|false Mapped gender, '' for blank, or false when unmappable.
     */
    private function mapGender(string $value): string|false
    {
        $value = Str::lower(trim($value));

        if ($value === '') {
            return '';
        }

        return match ($value) {
            'l', 'laki-laki', 'laki', 'male', 'm', 'pria' => 'Male',
            'p', 'perempuan', 'female', 'f', 'wanita' => 'Female',
            default => false,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/Y H:i', 'm/d/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
