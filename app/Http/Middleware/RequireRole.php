<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Use as ->middleware('role:doctor') on a route. */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $role = $request->session()->get('user.role');
        if (! in_array($role, $roles, true)) {
            abort(403, 'You do not have access to this.');
        }
        return $next($request);
    }
}
