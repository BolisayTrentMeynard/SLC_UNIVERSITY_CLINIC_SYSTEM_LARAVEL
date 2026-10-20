<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 401 when not logged in, 403 when not a nurse or doctor.
 * Re-reads the account on every request: a deactivated user is logged out, and a changed role takes effect at once.
 */
class RequireStaff
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->session()->get('user');
        if (! $user) {
            abort(401, 'Authentication required.');
        }
        $row = DB::table('users')->where('id', $user['id'])->where('active', 1)->first(['role', 'name']);
        if (! $row) {
            $request->session()->invalidate();
            abort(401, 'Authentication required.');
        }
        if ($row->role !== $user['role'] || $row->name !== $user['name']) {
            $user['role'] = $row->role;
            $user['name'] = $row->name;
            $request->session()->put('user', $user);
        }
        if (! in_array($user['role'], ['nurse', 'doctor'], true)) {
            abort(403, 'Clinic staff access required.');
        }
        return $next($request);
    }
}
