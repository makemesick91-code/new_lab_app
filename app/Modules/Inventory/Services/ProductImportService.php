<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductUnitRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportService
{
    public const HEADERS = [
        'sku',
        'name',
        'category',
        'unit',
        'supplier',
        'minimum_stock',
        'reorder_point',
        'reorder_quantity',
        'is_active',
    ];

    public function __construct(
        private readonly InventoryProductService $products,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly ProductUnitRepositoryInterface $units,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @return array{header: array<int, string>, example: array<int, string>}
     */
    public function templateRows(): array
    {
        return [
            'header' => self::HEADERS,
            'example' => [
                'BRG-001',
                'Zirconia Block',
                'Bahan',
                'pcs',
                'PT Supplier A',
                '5.0000',
                '2.5000',
                '10.0000',
                '1',
            ],
        ];
    }

    public function templateFilename(): string
    {
        return 'product-import-template.csv';
    }

    /**
     * @return array{created: int, errors: array<int, array{row: int, field: string, message: string}>}
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->parseCsv($file);
        $validation = $this->validateRows($rows);

        if ($validation['errors'] !== []) {
            return ['created' => 0, 'errors' => $validation['errors']];
        }

        $created = DB::transaction(function () use ($validation): int {
            $count = 0;

            foreach ($validation['payloads'] as $payload) {
                $this->products->create($payload);
                $count++;
            }

            return $count;
        });

        return ['created' => $created, 'errors' => []];
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

        $headerRow = $this->normalizeHeader($headerRow);
        $this->assertExpectedHeader($headerRow);

        $rows = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isBlankRow($data)) {
                continue;
            }

            $values = [];
            foreach (self::HEADERS as $index => $header) {
                $values[$header] = trim((string) ($data[$index] ?? ''));
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'values' => $values,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<int, string>
     */
    private function normalizeHeader(array $headerRow): array
    {
        if (isset($headerRow[0])) {
            $headerRow[0] = ltrim((string) $headerRow[0], "\xEF\xBB\xBF");
        }

        return array_map(
            static fn ($value) => Str::lower(trim((string) $value)),
            $headerRow
        );
    }

    /**
     * @param  array<int, string>  $headerRow
     */
    private function assertExpectedHeader(array $headerRow): void
    {
        $expected = array_map(static fn ($header) => Str::lower($header), self::HEADERS);

        if ($headerRow !== $expected) {
            throw new \InvalidArgumentException('Header CSV tidak valid. Gunakan template yang disediakan sistem.');
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

    /**
     * @param  array<int, array{row_number: int, values: array<string, string>}>  $rows
     * @return array{errors: array<int, array{row: int, field: string, message: string}>, payloads: array<int, array<string, mixed>>}
     */
    private function validateRows(array $rows): array
    {
        if ($rows === []) {
            return [
                'errors' => [[
                    'row' => 0,
                    'field' => 'file',
                    'message' => 'File CSV tidak memiliki baris data.',
                ]],
                'payloads' => [],
            ];
        }

        $branchId = $this->branchContext->requireId();
        $categoryMap = $this->categories->listActive($branchId)
            ->keyBy(static fn ($category) => Str::lower(trim($category->name)));
        $unitMap = $this->units->listActive()
            ->flatMap(static function ($unit) {
                return [
                    Str::lower(trim($unit->name)) => $unit,
                    Str::lower(trim($unit->symbol)) => $unit,
                ];
            });
        $supplierMap = $this->suppliers->listActive($branchId)
            ->keyBy(static fn ($supplier) => Str::lower(trim($supplier->name)));
        $existingCodes = Product::query()
            ->where('branch_id', $branchId)
            ->pluck('code')
            ->map(static fn ($code) => Str::lower(trim((string) $code)))
            ->all();
        $seenSkus = [];

        $errors = [];
        $payloads = [];

        foreach ($rows as $row) {
            $rowNumber = $row['row_number'];
            $values = $row['values'];
            $rowErrors = [];

            $sku = trim($values['sku']);
            if ($sku === '') {
                $rowErrors[] = ['field' => 'sku', 'message' => 'SKU wajib diisi.'];
            } elseif (mb_strlen($sku) > 50) {
                $rowErrors[] = ['field' => 'sku', 'message' => 'SKU maksimal 50 karakter.'];
            } else {
                $normalizedSku = Str::lower($sku);
                if (in_array($normalizedSku, $seenSkus, true)) {
                    $rowErrors[] = ['field' => 'sku', 'message' => 'SKU duplikat dalam file CSV.'];
                } elseif (in_array($normalizedSku, $existingCodes, true)) {
                    $rowErrors[] = ['field' => 'sku', 'message' => 'SKU sudah digunakan di cabang aktif.'];
                } else {
                    $seenSkus[] = $normalizedSku;
                }
            }

            $name = trim($values['name']);
            if ($name === '') {
                $rowErrors[] = ['field' => 'name', 'message' => 'Nama produk wajib diisi.'];
            } elseif (mb_strlen($name) > 150) {
                $rowErrors[] = ['field' => 'name', 'message' => 'Nama produk maksimal 150 karakter.'];
            }

            $categoryKey = Str::lower(trim($values['category']));
            $category = $categoryKey !== '' ? $categoryMap->get($categoryKey) : null;
            if ($categoryKey === '') {
                $rowErrors[] = ['field' => 'category', 'message' => 'Kategori wajib diisi.'];
            } elseif (! $category) {
                $rowErrors[] = ['field' => 'category', 'message' => 'Kategori tidak ditemukan di cabang aktif.'];
            }

            $unitKey = Str::lower(trim($values['unit']));
            $unit = $unitKey !== '' ? $unitMap->get($unitKey) : null;
            if ($unitKey === '') {
                $rowErrors[] = ['field' => 'unit', 'message' => 'Satuan wajib diisi.'];
            } elseif (! $unit) {
                $rowErrors[] = ['field' => 'unit', 'message' => 'Satuan tidak ditemukan.'];
            }

            $supplierName = trim($values['supplier']);
            if ($supplierName !== '' && ! $supplierMap->has(Str::lower($supplierName))) {
                $rowErrors[] = ['field' => 'supplier', 'message' => 'Supplier tidak ditemukan di cabang aktif.'];
            }

            foreach (['minimum_stock', 'reorder_point', 'reorder_quantity'] as $field) {
                $numericError = $this->validateDecimalField($values[$field], $field);
                if ($numericError !== null) {
                    $rowErrors[] = $numericError;
                }
            }

            $isActiveError = $this->validateIsActive($values['is_active']);
            if ($isActiveError !== null) {
                $rowErrors[] = $isActiveError;
            }

            foreach ($rowErrors as $error) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => $error['field'],
                    'message' => $error['message'],
                ];
            }

            if ($rowErrors === [] && $category && $unit) {
                $payloads[] = [
                    'code' => $sku,
                    'name' => $name,
                    'product_category_id' => $category->id,
                    'product_unit_id' => $unit->id,
                    'minimum_stock' => $this->parseDecimal($values['minimum_stock']) ?? 0,
                    'reorder_point' => $this->parseDecimal($values['reorder_point']) ?? 0,
                    'reorder_quantity' => $this->parseDecimal($values['reorder_quantity']) ?? 0,
                    'is_active' => $this->parseBoolean($values['is_active'], true),
                    'alert_enabled' => true,
                ];
            }
        }

        return [
            'errors' => $errors,
            'payloads' => $errors === [] ? $payloads : [],
        ];
    }

    /**
     * @return array{field: string, message: string}|null
     */
    private function validateDecimalField(string $value, string $field): ?array
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return ['field' => $field, 'message' => 'Nilai harus berupa angka.'];
        }

        if ((float) $value < 0) {
            return ['field' => $field, 'message' => 'Nilai tidak boleh negatif.'];
        }

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $value)) {
            return ['field' => $field, 'message' => 'Nilai mendukung maksimal 4 angka desimal.'];
        }

        return null;
    }

    /**
     * @return array{field: string, message: string}|null
     */
    private function validateIsActive(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        if ($this->parseBoolean($value) === null) {
            return ['field' => 'is_active', 'message' => 'Nilai is_active tidak valid. Gunakan 1, 0, true, false, yes, atau no.'];
        }

        return null;
    }

    private function parseDecimal(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function parseBoolean(string $value, bool $default = true): ?bool
    {
        if ($value === '') {
            return $default;
        }

        $normalized = Str::lower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }
}
