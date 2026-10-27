<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');
        if (! is_string($username) || ! is_string($password) || strlen($username) > 100 || strlen($password) > 200) {
            abort(400, 'Enter a valid username and password.');
        }

        $user = DB::table('users')->where('username', trim($username))->where('active', 1)->first();
        // Check a throwaway hash when the username is unknown so both failures take the same time.
        $ok = $user
            ? Hash::check($password, $user->password_hash)
            : (Hash::check($password, Hash::make('not-a-real-password')) && false);
        if (! $ok) {
            abort(401, 'Invalid username or password.');
        }

        $portal = $request->input('portal');
        if (is_string($portal) && $portal !== $user->role) {
            $portalName = ucfirst($portal);
            $actualName = ucfirst($user->role);
            abort(403, "This is the {$portalName} portal. Your account is registered as a {$actualName}. Please use the {$actualName} portal.");
        }

        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->role,
        ];
        $request->session()->put('user', $data);

        return response()->json(['user' => $data, 'csrfToken' => csrf_token()]);
    }

    public function session(Request $request)
    {
        $user = $request->session()->get('user');
        $user['patientId'] ??= null; // the Node app always sends this key
        return response()->json([
            'user' => $user,
            'csrfToken' => csrf_token(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }
}
