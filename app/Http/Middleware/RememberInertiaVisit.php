<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class RememberInertiaVisit
{
    /**
     * Without this, a validation failure after an Inertia visit redirects to the last full page load whenever the Referer header is withheld.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET')
            && $request->header('X-Inertia')
            && ! $request->prefetch()
            && $request->route() instanceof Route
            && $request->hasSession()
            && $response->isSuccessful()) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }
}
