<?php

namespace App\Modules\ClinicRoom\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicRoom\Requests\StoreClinicRoomRequest;
use App\Modules\ClinicRoom\Requests\UpdateClinicRoomRequest;
use App\Modules\ClinicRoom\Services\ClinicRoomService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClinicRoomController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClinicRoomService $rooms,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ClinicRoom::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        return view('settings.clinic-rooms.index', [
            'rooms' => $this->rooms->paginate($filters, 10),
            'filters' => $filters,
            'types' => ClinicRoom::TYPES,
            'statuses' => ClinicRoom::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ClinicRoom::class);

        return view('settings.clinic-rooms.create', [
            'types' => ClinicRoom::TYPES,
            'statuses' => ClinicRoom::STATUSES,
        ]);
    }

    public function store(StoreClinicRoomRequest $request): RedirectResponse
    {
        $this->authorize('create', ClinicRoom::class);

        $this->rooms->create($request->validated());

        return redirect()->route('settings.clinic-rooms.index')->with('status', 'Ruangan berhasil ditambahkan.');
    }

    public function edit(ClinicRoom $clinicRoom): View
    {
        $this->authorize('update', $clinicRoom);

        return view('settings.clinic-rooms.edit', [
            'room' => $clinicRoom,
            'types' => ClinicRoom::TYPES,
            'statuses' => ClinicRoom::STATUSES,
        ]);
    }

    public function update(UpdateClinicRoomRequest $request, ClinicRoom $clinicRoom): RedirectResponse
    {
        $this->authorize('update', $clinicRoom);

        $this->rooms->update($clinicRoom, $request->validated());

        return redirect()->route('settings.clinic-rooms.index')->with('status', 'Ruangan berhasil diperbarui.');
    }

    public function destroy(ClinicRoom $clinicRoom): RedirectResponse
    {
        $this->authorize('delete', $clinicRoom);

        $this->rooms->delete($clinicRoom);

        return redirect()->route('settings.clinic-rooms.index')->with('status', 'Ruangan berhasil dihapus.');
    }
}
