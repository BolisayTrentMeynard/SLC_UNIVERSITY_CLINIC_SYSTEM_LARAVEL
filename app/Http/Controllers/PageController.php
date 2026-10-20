<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Serves the doctor/ and nurse/ HTML pages from resources/pages after checking the login. */
class PageController extends Controller
{
    public function show(Request $request, string $folder, string $page)
    {
        $user = $request->session()->get('user');
        if (! $user || ! DB::table('users')->where('id', $user['id'])->where('active', 1)->exists()) {
            return redirect('/index.html');
        }
        if (! in_array($user['role'], ['nurse', 'doctor'], true)) {
            abort(403, 'This dashboard is restricted to its assigned role.');
        }
        // Same rule as the Node server: these two pages are for the doctor only.
        if ($folder === 'doctor' && in_array($page, ['users', 'school-years'], true) && $user['role'] !== 'doctor') {
            abort(403, 'This dashboard is restricted to its assigned role.');
        }

        $file = resource_path("pages/{$folder}/{$page}.html");
        abort_unless(is_file($file), 404, 'File not found.');

        return response()->file($file, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
