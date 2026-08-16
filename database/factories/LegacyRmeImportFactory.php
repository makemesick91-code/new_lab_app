<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A.
 *
 * @extends Factory<LegacyRmeImport>
 */
class LegacyRmeImportFactory extends Factory
{
    protected $model = LegacyRmeImport::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'origin_branch_id' => Branch::factory(),
            // A historical date by default; tests that exercise the date rules
            // always pass an explicit selected_rme_date.
            'selected_rme_date' => now()->subYears(5)->toDateString(),
            'earliest_native_rme_date_snapshot' => null,
            'original_filename' => 'rm-lama.pdf',
            'source_disk' => null,
            'source_pdf_path' => null,
            'source_pdf_sha256' => null,
            'normalized_content_hash' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'page_count' => null,
            'dpi' => null,
            'status' => LegacyRmeImport::STATUS_DRAFT,
        ];
    }

    /**
     * LEGACY-RME-SOURCE-RM-BINDING-1 — a factory-built import carries a COHERENT
     * source-RM binding by default: the Nomor RM of the patient it is filed
     * under, which is exactly what a correctly transcribed document would say.
     *
     * WHY DEFAULT IT AT ALL. Every existing legacy fixture builds a staged row
     * and then drives review/publish, and those now revalidate the binding. A
     * fixture with no binding would be a PRE-ENFORCEMENT row, which fails closed
     * by design — so leaving it unset would make dozens of unrelated tests
     * assert the wrong thing about their own subject.
     *
     * WHY THIS IS NOT THE FORBIDDEN BACKFILL. The rule this sprint locks is that
     * a HISTORICAL PRODUCTION row must never be given a manufactured source RM,
     * because doing so would claim an independent human confirmation that never
     * happened. A factory asserts nothing about production; it builds the row a
     * correct confirmation WOULD have produced. Nothing here runs in a migration
     * or against real data.
     *
     * BOTH OTHER CASES STAY REACHABLE, and the tests that matter use them:
     * `withoutSourceRm()` builds a genuine pre-enforcement row, and
     * `sourceRm()` builds a deliberately wrong or malformed one.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(fn (LegacyRmeImport $import) => $this->stampSourceRm($import))
            ->afterCreating(function (LegacyRmeImport $import): void {
                if ($this->stampSourceRm($import)) {
                    $import->save();
                }
            });
    }

    /**
     * A row created before source RM was captured — the pre-enforcement case.
     *
     * Setting the keys EXPLICITLY (to null) is what suppresses the default
     * stamp: {@see self::stampSourceRm()} distinguishes "never mentioned" from
     * "deliberately blank" by whether the attribute key exists.
     */
    public function withoutSourceRm(): static
    {
        return $this->state(fn (): array => [
            'source_rm_raw' => null,
            'source_rm_normalized' => null,
            'source_rm_resolution' => null,
        ]);
    }

    /**
     * An explicit source RM — used to build a WRONG or malformed binding.
     */
    public function sourceRm(string $raw, ?string $normalized = null): static
    {
        return $this->state(fn (): array => [
            'source_rm_raw' => $raw,
            'source_rm_normalized' => $normalized ?? $raw,
            'source_rm_resolution' => 'EXACT_UNIQUE',
        ]);
    }

    /**
     * @return bool whether anything was written
     */
    private function stampSourceRm(LegacyRmeImport $import): bool
    {
        if (array_key_exists('source_rm_raw', $import->getAttributes())) {
            return false;
        }

        $rm = $import->patient?->medical_record_number;

        if (! is_string($rm) || trim($rm) === '') {
            return false;
        }

        $import->source_rm_raw = $rm;
        $import->source_rm_normalized = $rm;
        $import->source_rm_resolution = 'EXACT_UNIQUE';

        return true;
    }

    public function readyForReview(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeImport::STATUS_READY_FOR_REVIEW,
            'source_disk' => 'local',
            'source_pdf_path' => 'rme-legacy/example/source.pdf',
            'source_pdf_sha256' => hash('sha256', 'legacy-rme-fixture'),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'page_count' => 2,
            'dpi' => 200,
            'uploaded_at' => now()->subMinutes(10),
        ]);
    }

    public function reviewed(): static
    {
        return $this->readyForReview()->state(fn () => [
            'status' => LegacyRmeImport::STATUS_REVIEWED,
            'reviewed_at' => now()->subMinutes(5),
        ]);
    }

    public function published(): static
    {
        return $this->reviewed()->state(fn () => [
            'status' => LegacyRmeImport::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeImport::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }
}
