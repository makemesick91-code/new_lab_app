<?php

namespace App\Modules\Inventory\Controllers\Concerns;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

trait RendersInventoryViews
{
    /**
     * Step 5 wires routes/controllers before Step 6 adds Blade views.
     */
    protected function renderInventoryView(string $view, array $data = []): View|Response
    {
        if (view()->exists($view)) {
            return view($view, $data);
        }

        return response('', 200);
    }
}
