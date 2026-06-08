<?php

namespace App\Modules\PaymentMethod\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Requests\StorePaymentMethodRequest;
use App\Modules\PaymentMethod\Requests\UpdatePaymentMethodRequest;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $isActive = $request->input('is_active');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'is_active' => ($isActive === null || $isActive === '') ? null : $request->boolean('is_active'),
            'type' => $request->string('type')->toString() ?: null,
        ];

        return view('settings.payment-methods.index', [
            'paymentMethods' => $this->paymentMethods->paginate($filters, 10),
            'filters' => $filters,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PaymentMethod::class);

        return view('settings.payment-methods.create', [
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        $this->authorize('create', PaymentMethod::class);

        $this->paymentMethods->create($request->validated());

        return redirect()->route('settings.payment-methods.index')->with('status', 'Metode pembayaran berhasil ditambahkan.');
    }

    public function edit(PaymentMethod $paymentMethod): View
    {
        $this->authorize('update', $paymentMethod);

        return view('settings.payment-methods.edit', [
            'paymentMethod' => $paymentMethod,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('update', $paymentMethod);

        $this->paymentMethods->update($paymentMethod, $request->validated());

        return redirect()->route('settings.payment-methods.index')->with('status', 'Metode pembayaran berhasil diperbarui.');
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('delete', $paymentMethod);

        $this->paymentMethods->delete($paymentMethod);

        return redirect()->route('settings.payment-methods.index')->with('status', 'Metode pembayaran berhasil dihapus.');
    }
}
