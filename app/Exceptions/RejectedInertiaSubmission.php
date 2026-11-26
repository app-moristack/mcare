<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RejectedInertiaSubmission
{
    private const AUTHORED_MESSAGE_STATUSES = [400, 403, 409, 422, 423];

    private const FALLBACK_MESSAGES = [
        403 => 'You do not have permission to do this.',
        404 => 'This record no longer exists or is not available to you.',
        409 => 'This record changed since you opened it. Reload the page and try again.',
        413 => 'The upload is too large.',
        422 => 'This could not be saved. Check the details and try again.',
        429 => 'Too many attempts. Wait a minute and try again.',
    ];

    /**
     * Status 419 is left to Inertia: an expired session has no previous page to return to.
     */
    public function __invoke(HttpExceptionInterface $exception, Request $request): ?RedirectResponse
    {
        $status = $exception->getStatusCode();
        if (! $request->header('X-Inertia') || $request->isMethod('GET') || $status < 400 || $status === 419) {
            return null;
        }
        if ($status >= 500 && config('app.debug')) {
            return null;
        }

        return back()->withErrors(['form' => $this->message($exception, $status)]);
    }

    private function message(HttpExceptionInterface $exception, int $status): string
    {
        $authored = trim($exception->getMessage());
        if ($authored !== '' && in_array($status, self::AUTHORED_MESSAGE_STATUSES, true)) {
            return $authored;
        }

        return self::FALLBACK_MESSAGES[$status] ?? 'Your changes were not saved. Try again.';
    }
}
