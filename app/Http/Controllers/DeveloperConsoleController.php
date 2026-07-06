<?php

namespace App\Http\Controllers;

use App\Support\DeveloperConsole\DeveloperConsoleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ENT-7 — Developer Assistance Console (read-only, permission-gated, audited).
 */
class DeveloperConsoleController extends Controller
{
    public function __construct(private readonly DeveloperConsoleService $console) {}

    public function index(Request $request): View
    {
        $this->console->recordAccess($request->user(), $request->ip());

        return view('dev-console.index', [
            'sections' => $this->console->overview(),
        ]);
    }
}
