<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MedicalRecordHandwritingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
    ) {}

    public function store(Request $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if($medicalRecord->clinic_visit_id !== $clinicVisit->id, 404);

        $this->authorize('update', $medicalRecord);

        if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
            throw ValidationException::withMessages([
                'handwriting_data' => 'Rekam medis yang sudah final tidak dapat diubah.',
            ]);
        }

        $request->validate([
            'handwriting_data' => ['required', 'string'],
        ]);

        $raw = $request->input('handwriting_data');

        // Strip the data URI prefix if present (data:image/png;base64,...)
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $raw);

        $decoded = base64_decode($base64, strict: true);

        // Must decode successfully and have valid PNG magic bytes
        if ($decoded === false || strlen($decoded) < 8 || substr($decoded, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages([
                'handwriting_data' => 'Data tulisan tangan tidak valid atau kosong.',
            ]);
        }

        $path = sprintf(
            'handwritings/%d/%d/handwriting_%s.png',
            $clinicVisit->branch_id,
            $clinicVisit->id,
            now()->format('YmdHis'),
        );

        Storage::disk('public')->put($path, $decoded);

        $hash = hash('sha256', $decoded);

        $existing = $this->handwritings->findByMedicalRecordId($medicalRecord->id);

        if ($existing !== null) {
            $this->handwritings->update($existing, [
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
            ]);
        } else {
            $this->handwritings->create([
                'medical_record_id' => $medicalRecord->id,
                'clinic_visit_id' => $clinicVisit->id,
                'branch_id' => $clinicVisit->branch_id,
                'doctor_id' => $clinicVisit->doctor_id,
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
                'created_by' => Auth::id(),
            ]);
        }

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Tulisan tangan RME berhasil disimpan.');
    }
}
