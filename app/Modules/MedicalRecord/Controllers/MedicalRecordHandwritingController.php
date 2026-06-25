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

        // Sprint 59 — handwriting RM is revisable after finalization. The
        // previous immutability lock is removed so doctors can correct or
        // append handwriting on any visit, including older finalized ones.

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

        // Sprint 59.1 — never overwrite saved handwriting with an accidental
        // blank canvas. A fully transparent / uniform PNG is rejected before
        // any storage or DB write, so previously saved strokes are preserved.
        if ($this->isBlankHandwriting($decoded)) {
            throw ValidationException::withMessages([
                'handwriting_data' => 'Tulisan tangan kosong tidak dapat disimpan dan tidak akan menghapus tulisan tangan yang sudah ada.',
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

    /**
     * Detect a blank handwriting submission (a fully transparent canvas or a
     * single uniform colour) so an accidental empty save never overwrites
     * previously stored handwriting. Pure-PHP PNG inspection — GD/Imagick are
     * not required, only zlib (always present). Only the 8-bit RGBA output of
     * canvas.toDataURL() is inspected; anything else is treated as non-blank to
     * avoid false rejections of legitimate content.
     */
    private function isBlankHandwriting(string $png): bool
    {
        if (strlen($png) < 8 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }

        $offset = 8;
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';

        while ($offset + 12 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);

            if ($type === 'IHDR') {
                $width = unpack('N', substr($data, 0, 4))[1];
                $height = unpack('N', substr($data, 4, 4))[1];
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $length;
        }

        if ($colorType !== 6 || $bitDepth !== 8 || $width === null || $width <= 0 || $height <= 0) {
            return false;
        }

        $raw = @zlib_decode($idat);
        if ($raw === false || $raw === '') {
            return false;
        }

        $bytesPerPixel = 4;
        $stride = $width * $bytesPerPixel;
        $previous = array_fill(0, $stride, 0);
        $position = 0;
        $allTransparent = true;
        $uniform = true;
        $firstPixel = null;

        for ($y = 0; $y < $height; $y++) {
            if ($position >= strlen($raw)) {
                return false;
            }

            $filter = ord($raw[$position++]);
            $line = array_values(unpack('C*', substr($raw, $position, $stride)));

            if (count($line) < $stride) {
                return false;
            }

            $position += $stride;

            // Reverse the PNG scanline filter so we read true pixel values.
            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bytesPerPixel ? $line[$x - $bytesPerPixel] : 0;
                $b = $previous[$x];
                $c = $x >= $bytesPerPixel ? $previous[$x - $bytesPerPixel] : 0;

                switch ($filter) {
                    case 0:
                        break;
                    case 1:
                        $line[$x] = ($line[$x] + $a) & 0xFF;
                        break;
                    case 2:
                        $line[$x] = ($line[$x] + $b) & 0xFF;
                        break;
                    case 3:
                        $line[$x] = ($line[$x] + intdiv($a + $b, 2)) & 0xFF;
                        break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $predictor = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $line[$x] = ($line[$x] + $predictor) & 0xFF;
                        break;
                    default:
                        return false;
                }
            }

            for ($x = 0; $x < $stride; $x += $bytesPerPixel) {
                $pixel = [$line[$x], $line[$x + 1], $line[$x + 2], $line[$x + 3]];

                if ($pixel[3] !== 0) {
                    $allTransparent = false;
                }

                if ($firstPixel === null) {
                    $firstPixel = $pixel;
                } elseif ($pixel !== $firstPixel) {
                    $uniform = false;
                }

                // Any non-transparent, non-uniform pixel means real content.
                if (! $allTransparent && ! $uniform) {
                    return false;
                }
            }

            $previous = $line;
        }

        return $allTransparent || $uniform;
    }
}
