<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away anyone the `manage-points` gate does not admit (403). It is ordered BEFORE route-model binding (see
 * bootstrap/app.php), so a non-administrator cannot tell a real redemption or reward from a missing one by the
 * difference between a 403 and a 404.
 */
class EnsurePointsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::allows('manage-points'), 403);

        return $next($request);
    }
}
