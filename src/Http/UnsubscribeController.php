<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Http;

use Asignua\FilamentScheduledReports\Support\Unsubscribe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET shows a confirmation, POST does it. A mail scanner that fetches every link in a message must
 * not unsubscribe anybody, and the one-click POST of a mail client (RFC 8058) must work without a CSRF token:
 * the signed URL is the credential in both cases.
 */
class UnsubscribeController
{
    public function show(Request $request, string $report, string $recipient): Response
    {
        $this->authorizeSignature($request);

        return response()->view('filament-scheduled-reports::unsubscribe.show', ['done' => false, 'action' => $request->fullUrl()]);
    }

    public function store(Request $request, string $report, string $recipient): Response
    {
        $this->authorizeSignature($request);

        Unsubscribe::apply($report, $recipient);

        // The same page whether or not anything was removed: a second click reveals nothing.
        return response()->view('filament-scheduled-reports::unsubscribe.show', ['done' => true, 'action' => '']);
    }

    private function authorizeSignature(Request $request): void
    {
        abort_unless(Unsubscribe::valid($request), 403);
    }
}
