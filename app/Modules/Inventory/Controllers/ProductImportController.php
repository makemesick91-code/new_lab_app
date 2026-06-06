<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\ImportProductCsvRequest;
use App\Modules\Inventory\Services\ProductImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly ProductImportService $imports,
    ) {}

    public function create(): View|Response
    {
        $this->authorize('create', Product::class);

        return $this->renderInventoryView('inventory.products.import');
    }

    public function template(): StreamedResponse
    {
        $this->authorize('create', Product::class);

        $rows = $this->imports->templateRows();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $rows['header']);
            fputcsv($handle, $rows['example']);
            fclose($handle);
        }, $this->imports->templateFilename(), [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function store(ImportProductCsvRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        try {
            $result = $this->imports->import($request->file('csv_file'));
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('inventory.products.import')
                ->withInput()
                ->withErrors(['csv_file' => $exception->getMessage()]);
        }

        if ($result['errors'] !== []) {
            return redirect()
                ->route('inventory.products.import')
                ->withInput()
                ->with('import_errors', $result['errors']);
        }

        return redirect()
            ->route('inventory.products.index')
            ->with('status', sprintf('%d produk berhasil diimpor dari CSV.', $result['created']));
    }
}
