<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets one GET URL serve both an Inertia page and its JSON API.
 *
 *   Route::get('/agent/proposals', ...)->middleware('page:Agent/Proposals/Index,manage-settings');
 *
 * - Inertia visits (`X-Inertia` header) and plain browser navigations get the
 *   page component, rendered with no props (the screen loads its data
 *   through the JSON endpoint).
 * - Anything that asks for JSON (`Accept: application/json`, as axios sends
 *   by default, or an XHR) falls through to the controller unchanged.
 * - `?format=csv` always falls through (download links).
 *
 * The optional ability is checked before the page renders so a user without
 * access gets a 403 page instead of an empty shell; the controller still
 * authorizes the JSON call on its own.
 */
class RenderPageUnlessJson
{
    public function handle(Request $request, Closure $next, string $component, ?string $ability = null): Response
    {
        if (! $request->isMethod('GET') || ! $this->wantsPage($request)) {
            return $next($request);
        }

        if ($ability !== null && $ability !== '') {
            abort_unless(Gate::allows($ability), 403);
        }

        return Inertia::render($component)->toResponse($request);
    }

    private function wantsPage(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return true;
        }
        if ($request->query('format') === 'csv') {
            return false;
        }

        return ! $request->wantsJson() && ! $request->ajax();
    }
}
