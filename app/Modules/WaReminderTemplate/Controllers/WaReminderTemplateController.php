<?php

namespace App\Modules\WaReminderTemplate\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use App\Modules\WaReminderTemplate\Requests\StoreWaReminderTemplateRequest;
use App\Modules\WaReminderTemplate\Requests\UpdateWaReminderTemplateRequest;
use App\Modules\WaReminderTemplate\Services\WaReminderTemplateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WaReminderTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WaReminderTemplateService $templates,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', WaReminderTemplate::class);

        $isActive = $request->input('is_active');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'trigger_type' => $request->string('trigger_type')->toString() ?: null,
            'audience_type' => $request->string('audience_type')->toString() ?: null,
            'is_active' => ($isActive === null || $isActive === '') ? null : $request->boolean('is_active'),
        ];

        return view('settings.wa-reminder-templates.index', [
            'templates' => $this->templates->paginate($filters, 15),
            'filters' => $filters,
            'triggerTypes' => WaReminderTemplate::triggerTypes(),
            'audienceTypes' => WaReminderTemplate::audienceTypes(),
            'triggerTypeLabels' => WaReminderTemplate::triggerTypeLabels(),
            'audienceTypeLabels' => WaReminderTemplate::audienceTypeLabels(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', WaReminderTemplate::class);

        return view('settings.wa-reminder-templates.create', [
            'triggerTypes' => WaReminderTemplate::triggerTypes(),
            'audienceTypes' => WaReminderTemplate::audienceTypes(),
            'triggerTypeLabels' => WaReminderTemplate::triggerTypeLabels(),
            'audienceTypeLabels' => WaReminderTemplate::audienceTypeLabels(),
        ]);
    }

    public function store(StoreWaReminderTemplateRequest $request): RedirectResponse
    {
        $this->authorize('create', WaReminderTemplate::class);

        $this->templates->create($request->validated());

        return redirect()->route('settings.wa-reminder-templates.index')
            ->with('status', 'Template reminder WA berhasil ditambahkan.');
    }

    public function edit(WaReminderTemplate $waReminderTemplate): View
    {
        $this->authorize('update', $waReminderTemplate);

        return view('settings.wa-reminder-templates.edit', [
            'template' => $waReminderTemplate,
            'triggerTypes' => WaReminderTemplate::triggerTypes(),
            'audienceTypes' => WaReminderTemplate::audienceTypes(),
            'triggerTypeLabels' => WaReminderTemplate::triggerTypeLabels(),
            'audienceTypeLabels' => WaReminderTemplate::audienceTypeLabels(),
        ]);
    }

    public function update(UpdateWaReminderTemplateRequest $request, WaReminderTemplate $waReminderTemplate): RedirectResponse
    {
        $this->authorize('update', $waReminderTemplate);

        $this->templates->update($waReminderTemplate, $request->validated());

        return redirect()->route('settings.wa-reminder-templates.index')
            ->with('status', 'Template reminder WA berhasil diperbarui.');
    }

    public function destroy(WaReminderTemplate $waReminderTemplate): RedirectResponse
    {
        $this->authorize('delete', $waReminderTemplate);

        $this->templates->delete($waReminderTemplate);

        return redirect()->route('settings.wa-reminder-templates.index')
            ->with('status', 'Template reminder WA berhasil dihapus.');
    }
}
