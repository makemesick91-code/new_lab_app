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

        // HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING: the stored URL is only
        // ever followed when it is a same-origin internal URL. Any external,
        // protocol-relative, or javascript:/data: value is refused (open-redirect
        // protection) and the user simply returns to the inbox.
        $url = $this->safeInternalUrl($row->data['url'] ?? null);

        return $url ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'Semua notifikasi ditandai terbaca.');
    }

    /**
     * Return the URL only when it is a safe, same-origin internal destination.
     * Rejects external hosts, protocol-relative (`//host`), and
     * javascript:/data: schemes so a stored URL can never be an open redirect.
     */
    private function safeInternalUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        // Dangerous schemes / protocol-relative are never followed.
        if (str_starts_with($url, '//') || preg_match('#^\s*(javascript|data|vbscript):#i', $url)) {
            return null;
        }

        // A relative internal path (single leading slash) is always same-origin.
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return ($appHost !== '' && strtolower($parts['host']) === $appHost) ? $url : null;
    }
}
