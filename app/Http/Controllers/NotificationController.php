<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 5) — in-app notification inbox.
 *
 * Strictly self-scoped: a user only ever reads/marks their OWN notifications
 * (resolved from auth, never from a request id parameter's owner).
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()
                ->notifications()
                ->paginate(20),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        // Self-scoped lookup: someone else's notification id simply 404s.
        $row = $request->user()->notifications()->where('id', $notification)->firstOrFail();

        if ($row->read_at === null) {
            $row->markAsRead();
        }

        $url = $row->data['url'] ?? null;

        return $url ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'Semua notifikasi ditandai terbaca.');
    }
}
