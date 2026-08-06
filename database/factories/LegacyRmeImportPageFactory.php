<?php

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * LEGACY-RME-PDF-1A.
 *
 * @extends Factory<LegacyRmeImportPage>
 */
class LegacyRmeImportPageFactory extends Factory
{
    protected $model = LegacyRmeImportPage::class;

    public function definition(): array
    {
        return [
            'legacy_import_id' => LegacyRmeImport::factory(),
            'page_number' => 1,
            'width' => null,
            'height' => null,
            'dpi' => null,
            'rotation' => 0,
            'background_disk' => null,
            'background_path' => null,
            'background_sha256' => null,
            'thumbnail_path' => null,
            'normalized_page_hash' => null,
            'status' => LegacyRmeImportPage::STATUS_PENDING,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeImportPage::STATUS_READY,
            'width' => 1654,
            'height' => 2339,
            'dpi' => 200,
            'background_disk' => 'local',
            'background_path' => 'rme-legacy/example/page-1.jpg',
            'background_sha256' => hash('sha256', 'legacy-rme-page-fixture'),
        ]);
    }
}
