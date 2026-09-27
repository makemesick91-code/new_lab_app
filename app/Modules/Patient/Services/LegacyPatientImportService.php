<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyImport\Services\LegacyImportDailyQuotaService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\Patient\Exceptions\LegacyPatientImportBlockedException;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        // FEATURE-LEGACY-IMPORT-HUB-1 — the canonical daily ceiling, shared with
        // the two archive importers so all three count the same way.
        private readonly LegacyImportDailyQuotaService $hubQuota,
        // REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — the canonical
        // audit trail. Staging columns record the batch's own lifecycle; this
        // records WHO did it, in the one place the rest of the system already
        // looks. Payloads are counts, statuses and ids — never a patient name,
        // never a KTP/NIK, never a filename.
        private readonly AuditLogService $audit,
    ) {}

    /** The `sys_audit_logs` entity type for a legacy patient import batch. */
    public const AUDIT_ENTITY = 'stg_legacy_patient_import_batches';

    public const AUDIT_UPLOADED = 'LEGACY_PATIENT_BATCH_UPLOADED';

    public const AUDIT_REVALIDATED = 'LEGACY_PATIENT_BATCH_REVALIDATED';

    public const AUDIT_CONFIRM_BLOCKED = 'LEGACY_PATIENT_BATCH_CONFIRM_BLOCKED';

    public const AUDIT_COMMITTED = 'LEGACY_PATIENT_BATCH_COMMITTED';

    public const AUDIT_CANCELLED = 'LEGACY_PATIENT_BATCH_CANCELLED';

    public const AUDIT_ROLLED_BACK = 'LEGACY_PATIENT_BATCH_ROLLED_BACK';

    /**
     * @return array{header: array<int, string>, example: array<int, string>}
     */
    public function templateRows(): array
    {
        return [
            'header' => array_map(static fn ($c) => $c['label'], self::COLUMNS),
            'example' => [
                '1', 'LEG-0001', '3201010101010001', 'TLK1', 'Ruang 1',
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

        $batch->refresh();

        $this->auditBatch(self::AUDIT_UPLOADED, $batch, $uploadedBy);

        return $batch;
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
            } elseif ($this->rmIsTaken($composedRm, $context)) {
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
            } elseif ($this->ktpIsTaken($ktp, $context)) {
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
     * CONFIRM THE BATCH. All of it, or none of it.
     *
     * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
     *
     * Sprint 62.3 committed the `valid` and `warning` rows and left the `error`
     * rows behind. A 500-row file with 3 bad rows produced 497 real patients and
     * an unreconcilable question: WHICH 497? The operator still holds one file,
     * the estate now holds part of it, and nothing in either tells them where the
     * boundary fell. So a blocking error now refuses the WHOLE batch. The file
     * is corrected outside the system and uploaded again — the source file stays
     * the unit of work, because it is the only artefact the operator can reason
     * about.
     *
     * THE ORDER OF THE THREE GATES IS LOAD-BEARING.
     *
     *  1. SOURCE IDENTITY, before anything else. The batch is only meaningful as
     *     a claim about a specific file; if the stored bytes no longer hash to
     *     what was recorded at upload, then whatever the operator reviewed is not
     *     what would be imported, and no amount of row validation fixes that.
     *
     *  2. REVALIDATION, OUTSIDE the insert transaction. This is deliberate and
     *     it is the one subtle thing here. Revalidation WRITES the refreshed
     *     verdict onto the staging rows, and that verdict is the evidence the
     *     operator needs in order to act. Inside the transaction those writes
     *     would be unwound by the very throw that refuses the import, and the
     *     operator would be told "blocked" while the preview still showed the
     *     stale all-clear. So the verdict is persisted first and the refusal is
     *     raised second.
     *
     *  3. THE INSERTS, inside ONE transaction, under a header row lock.
     *
     * THE WINDOW BETWEEN 2 AND 3 IS CLOSED BY THE DATABASE, NOT BY HOPE.
     * `mst_patients.medical_record_number` and `.ktp_number` are both UNIQUE, so
     * a patient registered by someone else in that window makes an INSERT fail,
     * which unwinds the transaction and leaves zero rows behind. The locking
     * probe below exists to turn that into a sentence an operator can read, not
     * to provide the guarantee; the constraint provides the guarantee.
     *
     * IDEMPOTENT UNDER CONCURRENCY. The header is locked and its state re-read
     * INSIDE the transaction, so of two simultaneous confirmations exactly one
     * finds a confirmable batch. The pre-transaction check is a courtesy for the
     * common case; it is not where the decision is taken.
     *
     * THE DAILY CEILING IS STILL CHARGED HERE, AND ONLY HERE, in accepted rows
     * per branch. Since REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1 the
     * shipped ceiling is NULL, so `reserveMany` returns without limiting — the
     * call is kept because removing it would delete the mechanism, not the
     * policy, and a ceiling must remain restorable by configuration alone.
     *
     * @throws LegacyPatientImportBlockedException when the batch may not import.
     */
    public function commit(LegacyPatientImportBatch $batch, ?int $committedBy): LegacyPatientImportBatch
    {
        /*
         * Re-confirming an already-confirmed batch is a no-op, not an error. A
         * double submit or a refreshed tab must never be able to import twice,
         * and must never look like a failure to an operator who already
         * succeeded. The authoritative version of this check runs under the row
         * lock below.
         */
        if ($batch->isCommitted()) {
            return $batch;
        }

        /*
         * LIFECYCLE GATE. Only a batch awaiting review may be confirmed. This is
         * about the batch's STATE, not about its verdict — a cancelled, failed,
         * committing or rolled-back batch is not a candidate no matter how clean
         * its counters look.
         */
        if (! $batch->isAwaitingReview()) {
            throw $this->refuse(
                $batch,
                $committedBy,
                LegacyPatientImportBlockedException::REASON_NOT_READY,
                'Batch tidak dapat diimpor (sudah diproses, dibatalkan, atau identitas berkasnya tidak dapat dibuktikan).',
            );
        }

        /*
         * Whether the batch ALREADY carried errors when confirmation was
         * attempted. Captured before revalidation so the refusal can tell the
         * operator which of two different things went wrong: their file was
         * already bad (ERROR_ROWS — fix the file), or their file was fine and
         * the database moved underneath them (REVALIDATION_FAILED — the data is
         * stale). Same refusal, different remedy.
         */
        $hadErrorsBefore = $batch->error_rows > 0;

        // GATE 1 — the file the operator reviewed is the file that will import.
        $this->assertSourceUnchanged($batch, $committedBy);

        /*
         * GATE 2 — REVALIDATE, ALWAYS, AND DECIDE ON THAT.
         *
         * The stored counters are never the gate. They are a snapshot of a past
         * moment, and the whole point of this gate is that the moment has passed:
         * between preview and confirmation another operator may have registered
         * one of these patients, or the branch may have been disabled, or a
         * conflicting patient may have been deleted and the batch become valid
         * again. Deciding on the snapshot would refuse a batch that is now fine
         * and — far worse — could admit one that is now not.
         *
         * This also runs for a batch that already showed errors. It costs a
         * bounded number of queries and it means a stale refusal can never
         * become permanent: a conflict that goes away lets the batch through,
         * without forcing the operator to re-upload a file that was correct.
         *
         * The verdict is persisted here, OUTSIDE the insert transaction, so the
         * refusal below cannot unwind the evidence the operator needs.
         */
        $batch = $this->revalidate($batch, $committedBy);

        if ($batch->error_rows > 0) {
            throw $this->refuse(
                $batch,
                $committedBy,
                $hadErrorsBefore
                    ? LegacyPatientImportBlockedException::REASON_ERROR_ROWS
                    : LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED,
                $hadErrorsBefore
                    ? sprintf(
                        'Import dibatalkan: %d baris berstatus ERROR. Seluruh batch tidak diimpor. Perbaiki berkas sumber lalu unggah ulang.',
                        $batch->error_rows,
                    )
                    : sprintf(
                        'Verifikasi ulang saat konfirmasi menemukan %d baris ERROR (data master berubah sejak pratinjau). Seluruh batch tidak diimpor.',
                        $batch->error_rows,
                    ),
                $this->blockingFindings($batch),
            );
        }

        /*
         * Zero errors, but also nothing to import. Confirming would report a
         * successful import of nobody, which reads as success.
         */
        if (! $batch->committable()) {
            throw $this->refuse(
                $batch,
                $committedBy,
                LegacyPatientImportBlockedException::REASON_NOT_READY,
                'Batch tidak memuat baris yang dapat diimpor.',
            );
        }

        // GATE 3 — all of it, or none of it.
        try {
            DB::transaction(function () use ($batch, $committedBy): void {
                /** @var LegacyPatientImportBatch|null $locked */
                $locked = LegacyPatientImportBatch::query()
                    ->whereKey($batch->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $locked || ! $locked->committable()) {
                    throw new LegacyPatientImportBlockedException(
                        LegacyPatientImportBlockedException::REASON_NOT_READY,
                        'Batch sudah diproses oleh permintaan lain.',
                    );
                }

                $locked->update(['status' => LegacyPatientImportBatch::STATUS_COMMITTING]);

                $committed = 0;

                /** @var array<int, int> $unitsByBranch branch id => committed rows */
                $unitsByBranch = [];

                $rows = LegacyPatientImportRow::query()
                    ->where('batch_id', $locked->id)
                    ->whereIn('status', [LegacyPatientImportRow::STATUS_VALID, LegacyPatientImportRow::STATUS_WARNING])
                    ->orderBy('row_number')
                    ->lockForUpdate()
                    ->get();

                foreach ($rows as $row) {
                    $data = $row->normalized_payload ?? [];
                    $rm = $data['medical_record_number'] ?? null;
                    $ktp = $data['ktp_number'] ?? null;

                    /*
                     * A committable row without a composed RM is a contradiction:
                     * a missing RM is a blocking error, so revalidation above
                     * would have refused the batch. Reaching here means the
                     * staged verdict and the staged payload disagree, and the
                     * safe reading of a contradiction is to import nothing.
                     */
                    if (! $rm) {
                        throw new LegacyPatientImportBlockedException(
                            LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED,
                            sprintf('Baris %d tidak memiliki Nomor RM final. Seluruh batch tidak diimpor.', $row->row_number),
                        );
                    }

                    $rmTaken = Patient::withTrashed()->where('medical_record_number', $rm)->lockForUpdate()->exists();
                    $ktpTaken = $ktp ? Patient::withTrashed()->where('ktp_number', $ktp)->lockForUpdate()->exists() : false;

                    /*
                     * The preview-to-confirm race. Sprint 62.3 skipped this row
                     * and imported the rest; that is the partial import this
                     * revision exists to prevent, so it now refuses the batch.
                     */
                    if ($rmTaken || $ktpTaken) {
                        throw new LegacyPatientImportBlockedException(
                            LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED,
                            sprintf(
                                'Baris %d menjadi duplikat (RM/KTP terdaftar saat konfirmasi berjalan). Seluruh batch tidak diimpor.',
                                $row->row_number,
                            ),
                        );
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
                        'import_batch_id' => $locked->id,
                    ]);

                    $row->update([
                        'status' => LegacyPatientImportRow::STATUS_COMMITTED,
                        'committed_patient_id' => $patient->id,
                    ]);
                    $committed++;

                    $chargedBranchId = (int) ($data['branch_id'] ?? 0);

                    if ($chargedBranchId > 0) {
                        $unitsByBranch[$chargedBranchId] = ($unitsByBranch[$chargedBranchId] ?? 0) + 1;
                    }
                }

                /*
                 * Every committable row was inserted, or we never got here. A
                 * count that disagrees with the verdict means the two sources
                 * drifted, and importing a number of patients nobody approved is
                 * worse than importing none.
                 */
                if ($committed !== ($locked->valid_rows + $locked->warning_rows)) {
                    throw new LegacyPatientImportBlockedException(
                        LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED,
                        sprintf(
                            'Jumlah baris yang diimpor (%d) tidak sama dengan jumlah baris yang disetujui (%d). Seluruh batch dibatalkan.',
                            $committed,
                            $locked->valid_rows + $locked->warning_rows,
                        ),
                    );
                }

                $this->hubQuota->reserveMany(LegacyImportType::LEGACY_PATIENT, $unitsByBranch);

                $locked->update([
                    'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
                    'committed_rows' => $committed,
                    'committed_by' => $committedBy,
                    'committed_at' => now(),
                ]);
            });
        } catch (LegacyPatientImportBlockedException $e) {
            /*
             * The transaction is already unwound, so zero patients exist. Refresh
             * the verdict so the operator's next look at the preview shows why,
             * then re-raise: this is a refusal, not a silent partial success.
             */
            $this->revalidate($batch->refresh(), $committedBy);

            throw $this->refuse($batch->refresh(), $committedBy, $e->reason, $e->getMessage(), $e->findings);
        } catch (QueryException $e) {
            /*
             * The UNIQUE constraint fired — the race the locking probe above
             * tries to catch first, won on the insert instead. The outcome is
             * identical (nothing was committed); only the message differs, and it
             * must not be a raw SQL string.
             */
            $this->revalidate($batch->refresh(), $committedBy);

            throw $this->refuse(
                $batch->refresh(),
                $committedBy,
                LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED,
                'Konfirmasi ditolak: identitas pasien (RM/KTP) sudah terdaftar saat impor berjalan. Seluruh batch tidak diimpor.',
            );
        }

        $batch->refresh();

        $this->auditBatch(self::AUDIT_COMMITTED, $batch, $committedBy);

        return $batch;
    }

    /**
     * Re-run the FULL row validation against the database as it stands now, and
     * persist the result onto the staging rows and the batch counters.
     *
     * WHY THE WHOLE RULE SET, NOT JUST THE DATABASE-DEPENDENT PART. The
     * structural rules (name present, RM digits, date validity, gender, email)
     * are pure functions of the frozen `raw_payload`, so re-running them cannot
     * change their answer. They are re-run anyway because "revalidation" that
     * skipped them would be a narrower guarantee than its name, and the next
     * person to read this would have to prove the narrowing was still safe.
     *
     * WHY IT IS STILL CHEAP. The expensive rules are the two identity lookups,
     * and they are prefetched: the batch's own composed RMs and KTPs are derived
     * first, then resolved in chunked `whereIn` queries, so the database round
     * trips are bounded by the number of chunks rather than by the number of
     * rows. Nothing here scans the patient estate — the prefetch asks only about
     * the identities this batch actually claims.
     *
     * IN-FILE DUPLICATES ARE RE-ASSERTED from scratch (fresh `seen` lists), so a
     * duplicate inside the file is caught here exactly as it was at upload.
     */
    public function revalidate(LegacyPatientImportBatch $batch, ?int $actorId = null): LegacyPatientImportBatch
    {
        /*
         * REVALIDATION REVISES A PENDING VERDICT. It has no business touching a
         * batch whose lifecycle has already concluded, and this guard is not
         * defensive decoration — it closes a defect found by the two-process
         * concurrency harness.
         *
         * When two operators confirm the same batch, the loser's refusal is
         * raised from inside the transaction and its catch block refreshes the
         * verdict. Without this guard that refresh ran against a batch the WINNER
         * had just committed: it reset `status` from `committed` back to
         * `validated` while `committed_rows` stayed at 5, so the header claimed a
         * batch awaiting review that had in fact created five patients. Rollback
         * checks `isCommitted()`, so those five patients would have become
         * impossible to withdraw through the workflow that created them.
         *
         * Returning the batch untouched is correct rather than merely safe: there
         * is no pending verdict left to revise.
         */
        /*
         * Re-read the lifecycle from the DATABASE, not from the instance handed
         * in. A caller can legitimately hold a model loaded before another
         * request committed the batch, and trusting that stale copy would walk
         * straight past this guard into rewriting the verdict of a batch that has
         * already created patients — the exact defect the guard exists to stop.
         */
        $current = LegacyPatientImportBatch::query()->whereKey($batch->getKey())->first();

        if (! $current || ! $current->isAwaitingReview()) {
            return $current ?? $batch;
        }

        $batch = $current;

        $rows = LegacyPatientImportRow::query()
            ->where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->get();

        $context = $this->buildLookupContext();

        // Pass 1 — derive the identity keys this batch claims. No patient query.
        $claimedRm = [];
        $claimedKtp = [];

        foreach ($rows as $row) {
            $keys = $this->deriveIdentityKeys($row->raw_payload ?? [], $context);

            if ($keys['rm'] !== null) {
                $claimedRm[] = $keys['rm'];
            }

            if ($keys['ktp'] !== null) {
                $claimedKtp[] = $keys['ktp'];
            }
        }

        // Pass 2 — resolve every claim in a bounded number of round trips.
        $context['takenRm'] = $this->takenValues('medical_record_number', $claimedRm);
        $context['takenKtp'] = $this->takenValues('ktp_number', $claimedKtp);

        $seenRm = [];
        $seenKtp = [];
        $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];
        $summary = [];

        foreach ($rows as $row) {
            $mapped = $this->validateAndMapRow($row->raw_payload ?? [], $context, $seenRm, $seenKtp);
            $counts[$mapped['status']]++;

            foreach ($mapped['errors'] as $message) {
                $summary[] = ['row' => $row->row_number, 'severity' => 'error', 'message' => $message];
            }

            /*
             * A row that already became a patient is history, not a pending
             * verdict. The guard above should mean we never see one here; the
             * check is repeated at row level so the invariant is local to the
             * write rather than inferred from a caller three screens away.
             *
             * `committed_patient_id` is likewise never in the update payload.
             */
            if ($row->committed_patient_id !== null) {
                continue;
            }

            $row->update($mapped['attributes']);
        }

        $batch->update([
            'status' => LegacyPatientImportBatch::STATUS_VALIDATED,
            'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['warning'],
            'error_rows' => $counts['error'],
            'error_summary' => $summary !== [] ? $summary : null,
            'revalidated_at' => now(),
            'revalidation_attempts' => (int) $batch->revalidation_attempts + 1,
        ]);

        $batch->refresh();

        $this->auditBatch(self::AUDIT_REVALIDATED, $batch, $actorId);

        return $batch;
    }

    /**
     * Cancel a batch that has not been imported.
     *
     * A cancelled batch KEEPS its staging rows and its header. Sprint 62.3's
     * discard soft-deleted the whole batch, which removed it from every operator
     * surface: the evidence that a file was uploaded, reviewed and rejected
     * disappeared along with the batch. Cancellation is a decision worth
     * recording, so it is recorded.
     *
     * Row verdicts are left exactly as they were. A row that was `error` really
     * was an error; overwriting that with a lifecycle status would destroy the
     * reason the batch was cancelled in the first place.
     *
     * There is no cancellation after commitment. Withdrawing patients that were
     * already created is {@see rollback()}, which is transactional and refuses
     * when any of them already has a visit. A "cancel and delete the patients"
     * button would be a destructive operation wearing the word cancel.
     *
     * @throws RuntimeException when the batch has already been imported.
     */
    public function cancel(LegacyPatientImportBatch $batch, ?int $cancelledBy, ?string $reason = null): LegacyPatientImportBatch
    {
        // Already cancelled — idempotent, so a double click cannot look like a failure.
        if ($batch->isCancelled()) {
            return $batch;
        }

        if (! $batch->isCancellable()) {
            throw new RuntimeException('Batch yang sudah di-commit tidak dapat dibatalkan; gunakan rollback.');
        }

        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;

        DB::transaction(function () use ($batch, $cancelledBy, $reason): void {
            /** @var LegacyPatientImportBatch|null $locked */
            $locked = LegacyPatientImportBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->isCancellable()) {
                throw new RuntimeException('Batch sudah diproses oleh permintaan lain.');
            }

            $locked->update([
                'status' => LegacyPatientImportBatch::STATUS_CANCELLED,
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => now(),
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);
        });

        $batch->refresh();

        $this->auditBatch(self::AUDIT_CANCELLED, $batch, $cancelledBy);

        return $batch;
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

        $batch->refresh();

        $this->auditBatch(self::AUDIT_ROLLED_BACK, $batch, $rolledBackBy);

        return $batch;
    }

    /**
     * @deprecated REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — use
     * {@see cancel()}. Kept as a thin forward so any caller outside this module
     * keeps working, and so the behavioural change is visible in one place
     * rather than duplicated: discarding no longer soft-deletes the batch, it
     * cancels it and preserves the evidence.
     */
    public function discard(LegacyPatientImportBatch $batch): void
    {
        $this->cancel($batch, auth()->id(), null);
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

        /*
         * Resolved once. The previous per-row Branch::find() issued one query per
         * exported row, so a 20,000-row report was 20,000 queries to render at
         * most a handful of distinct branch codes.
         */
        $branchCodes = Branch::query()
            ->whereIn('id', $rows->pluck('matched_branch_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        foreach ($rows as $row) {
            $branch = (string) ($row->matched_branch_id ? ($branchCodes[$row->matched_branch_id] ?? '') : '');
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
                /*
                 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — free
                 * text is neutralised on the way out. `patient_name` and
                 * `$message` both carry operator-supplied content, and this file
                 * exists to be opened in a spreadsheet. The row number, branch
                 * code, composed RM and masked KTP are server-generated from a
                 * fixed vocabulary, but they go through the same call rather than
                 * relying on a reader to re-derive which columns were safe.
                 */
                $lines[] = [
                    $this->csvSafe((string) $row->row_number),
                    $this->csvSafe($row->patient_name),
                    $this->csvSafe($branch),
                    $this->csvSafe($row->generated_medical_record_number),
                    $this->csvSafe($row->ktp_masked),
                    $this->csvSafe($row->status),
                    $this->csvSafe($severity),
                    $this->csvSafe((string) $message),
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
     * GATE 1 — the stored source file still hashes to what was recorded at upload.
     *
     * A batch is a claim about one specific file. If the bytes changed, then the
     * rows the operator reviewed are not the rows that would be imported, and
     * every downstream check would be validating the wrong document carefully.
     *
     * A MISSING file is treated the same way as a changed one. The distinction
     * matters for the message, not for the decision: what cannot be re-proven
     * must not be imported.
     *
     * The batch is moved to FAILED rather than left confirmable. Source identity
     * cannot repair itself, so leaving the batch importable would invite an
     * operator to press confirm repeatedly against a permanent refusal. FAILED is
     * still cancellable, which is the action actually available to them.
     *
     * @throws LegacyPatientImportBlockedException
     */
    private function assertSourceUnchanged(LegacyPatientImportBatch $batch, ?int $actorId): void
    {
        /*
         * Nothing to compare against. A batch staged before this revision may
         * legitimately carry neither a path nor a hash, and refusing those would
         * turn a deploy into an outage for work already in review. What is NOT
         * done is to invent a pass: the absence of a hash is recorded as
         * unverified by leaving `source_verified_at` null, so no later reader can
         * mistake silence for proof.
         */
        if (! $batch->stored_path || ! $batch->file_hash) {
            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($batch->stored_path)) {
            $batch->update([
                'status' => LegacyPatientImportBatch::STATUS_FAILED,
                'error_summary' => [[
                    'row' => 0,
                    'severity' => 'error',
                    'message' => 'Berkas sumber tidak ditemukan lagi di penyimpanan; batch tidak dapat diimpor. Unggah ulang berkas.',
                ]],
            ]);

            throw $this->refuse(
                $batch->refresh(),
                $actorId,
                LegacyPatientImportBlockedException::REASON_SOURCE_MISSING,
                'Konfirmasi ditolak: berkas sumber tidak ditemukan lagi, sehingga isi yang ditinjau tidak dapat dibuktikan. Unggah ulang berkas.',
            );
        }

        $actual = hash('sha256', (string) $disk->get($batch->stored_path));

        if (! hash_equals((string) $batch->file_hash, $actual)) {
            $batch->update([
                'status' => LegacyPatientImportBatch::STATUS_FAILED,
                'error_summary' => [[
                    'row' => 0,
                    'severity' => 'error',
                    'message' => 'Berkas sumber berubah setelah diunggah (SHA256 tidak cocok); batch tidak dapat diimpor. Unggah ulang berkas.',
                ]],
            ]);

            throw $this->refuse(
                $batch->refresh(),
                $actorId,
                LegacyPatientImportBlockedException::REASON_SOURCE_CHANGED,
                'Konfirmasi ditolak: berkas sumber berubah setelah pratinjau (SHA256 tidak cocok). Seluruh batch tidak diimpor.',
            );
        }

        $batch->update(['source_verified_at' => now()]);
    }

    /**
     * Derive the identity keys a raw row claims, WITHOUT touching mst_patients.
     *
     * This is the prefetch's shopping list, and it must ask about exactly the
     * identities {@see validateAndMapRow()} will later test — otherwise a missing
     * key would read as "not taken", which fails OPEN. It therefore composes the
     * RM the same way, from the same inputs, through the same service.
     *
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $context
     * @return array{rm: ?string, ktp: ?string}
     */
    private function deriveIdentityKeys(array $values, array $context): array
    {
        $rm = null;

        $branchLabel = trim($values['branch'] ?? '');
        $branch = $branchLabel === ''
            ? null
            : ($context['branchByCode']->get(Str::upper($branchLabel))
                ?? $context['branchByName']->get(Str::lower($branchLabel)));

        $manualRm = $this->digitsOnly($values['manual_rm_number'] ?? '');
        $registeredAt = $this->parseDate($values['timestamp'] ?? '');

        if ($branch && $manualRm !== '' && $registeredAt !== null) {
            $rm = $this->rmNumbers->composeForRegistration($branch->code, $registeredAt, $manualRm);
        }

        $ktp = $this->digitsOnly($values['ktp_number'] ?? '');

        return [
            'rm' => $rm,
            'ktp' => ($ktp !== '' && mb_strlen($ktp) <= 16) ? $ktp : null,
        ];
    }

    /**
     * Which of the given values are already present on a patient — INCLUDING
     * soft-deleted ones, because a trashed patient still owns its RM and KTP
     * through the unique index, and an import that ignored that would fail on
     * the INSERT instead of in review.
     *
     * Chunked so the query stays inside every driver's bound-parameter limit; a
     * 20,000-row batch must not become one statement with 20,000 placeholders.
     *
     * @param  array<int, string>  $values
     * @return array<string, true>
     */
    private function takenValues(string $column, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn ($v) => $v !== null && $v !== '')));

        if ($values === []) {
            return [];
        }

        $taken = [];

        foreach (array_chunk($values, 1000) as $chunk) {
            $found = Patient::withTrashed()
                ->whereIn($column, $chunk)
                ->pluck($column)
                ->all();

            foreach ($found as $value) {
                $taken[(string) $value] = true;
            }
        }

        return $taken;
    }

    /**
     * Is this composed RM already owned by a patient?
     *
     * Consults the prefetched set when revalidation supplied one, and falls back
     * to the live query otherwise so the upload path keeps its existing
     * behaviour unchanged. A prefetched set is authoritative only because
     * {@see deriveIdentityKeys()} guarantees it was asked about this exact value.
     *
     * @param  array<string, mixed>  $context
     */
    private function rmIsTaken(string $composedRm, array $context): bool
    {
        if (isset($context['takenRm']) && is_array($context['takenRm'])) {
            return isset($context['takenRm'][$composedRm]);
        }

        return $this->rmNumbers->exists($composedRm);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ktpIsTaken(string $ktp, array $context): bool
    {
        if (isset($context['takenKtp']) && is_array($context['takenKtp'])) {
            return isset($context['takenKtp'][$ktp]);
        }

        return Patient::withTrashed()->where('ktp_number', $ktp)->exists();
    }

    /**
     * Record a refusal in the audit trail and build the exception to throw.
     *
     * Every refusal goes through here so that "confirmation was attempted and
     * declined" is never an event that happened only in an HTTP response.
     *
     * @param  array<int, array{row: int, severity: string, message: string}>  $findings
     */
    private function refuse(
        LegacyPatientImportBatch $batch,
        ?int $actorId,
        string $reason,
        string $message,
        array $findings = [],
    ): LegacyPatientImportBlockedException {
        $this->auditBatch(self::AUDIT_CONFIRM_BLOCKED, $batch, $actorId, ['reason' => $reason]);

        return new LegacyPatientImportBlockedException($reason, $message, $findings);
    }

    /**
     * The blocking findings of a batch, bounded, for an operator-facing summary.
     *
     * Bounded because a 20,000-row batch of identical errors would otherwise
     * render 20,000 lines into a flash message. The full set is always available
     * in the downloadable report and on the rows themselves.
     *
     * @return array<int, array{row: int, severity: string, message: string}>
     */
    private function blockingFindings(LegacyPatientImportBatch $batch, int $limit = 20): array
    {
        $findings = [];

        $rows = LegacyPatientImportRow::query()
            ->where('batch_id', $batch->id)
            ->where('status', LegacyPatientImportRow::STATUS_ERROR)
            ->orderBy('row_number')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            foreach (($row->errors ?? []) as $message) {
                $findings[] = ['row' => $row->row_number, 'severity' => 'error', 'message' => (string) $message];
            }
        }

        return $findings;
    }

    /**
     * Write one lifecycle event to the canonical audit trail.
     *
     * PII POLICY, ENFORCED BY CONSTRUCTION RATHER THAN BY CARE: the payload is
     * assembled here from counts, statuses and identifiers only. The original
     * filename is deliberately excluded — operators name files after people, so
     * a filename is a plausible carrier of a patient's name into a log that is
     * read by everybody.
     *
     * A failure to audit must never become a failure to import, or the audit
     * trail turns into an availability risk for clinical work; it is swallowed
     * here and the application log keeps the trace.
     *
     * @param  array<string, mixed>  $extra
     */
    private function auditBatch(string $action, LegacyPatientImportBatch $batch, ?int $actorId, array $extra = []): void
    {
        try {
            $this->audit->log(
                self::AUDIT_ENTITY,
                $batch->id,
                $action,
                null,
                array_merge([
                    'batch_uuid' => $batch->uuid,
                    'status' => $batch->status,
                    'total_rows' => (int) $batch->total_rows,
                    'valid_rows' => (int) $batch->valid_rows,
                    'warning_rows' => (int) $batch->warning_rows,
                    'error_rows' => (int) $batch->error_rows,
                    'committed_rows' => (int) $batch->committed_rows,
                    'revalidation_attempts' => (int) $batch->revalidation_attempts,
                    'actor_user_id' => $actorId,
                ], $extra),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Neutralise spreadsheet formula injection in an exported cell.
     *
     * Mirrors LabCapacity\Controllers\LabTechnicianCapacityController::csvSafe().
     * The report carries operator-supplied free text — a patient named `=cmd()`
     * and a validation message quoting it both reach a spreadsheet — and the
     * file exists to be opened in one.
     */
    private function csvSafe(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
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
