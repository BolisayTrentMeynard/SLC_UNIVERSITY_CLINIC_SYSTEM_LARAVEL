<?php

namespace App\Http\Controllers;

use App\Support\Check;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** User management, doctor only (port of users.controller.js + users.service.js). */
class UserController extends Controller
{
    private const MESSAGE = 'Only a doctor can manage user accounts.';

    private function actor(Request $request): array
    {
        $actor = $request->session()->get('user');
        Check::requireRole($actor, ['doctor'], self::MESSAGE);
        return $actor;
    }

    private function publicUser(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'username' => $row->username,
            'name' => $row->name,
            'role' => $row->role,
            'active' => (int) $row->active === 1,
            'patientId' => $row->patient_id ?: null,
        ];
    }

    private function role(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['nurse', 'doctor'], true)) {
            abort(400, 'Select a valid user role.');
        }
        return $value;
    }

    public function list(Request $request)
    {
        $this->actor($request);
        $rows = DB::table('users')->orderBy('name')->orderBy('username')
            ->get(['id', 'username', 'name', 'role', 'active', 'patient_id']);
        return response()->json(['users' => $rows->map(fn ($r) => $this->publicUser($r))->all()]);
    }

    public function create(Request $request)
    {
        $this->actor($request);
        $body = $request->json()->all();
        $name = Check::text($body['name'] ?? null, 'Name', 200, true);
        $username = Check::text($body['username'] ?? null, 'Username', 100, true);
        if (! preg_match('/^[a-zA-Z0-9._@-]{3,100}$/D', $username)) {
            abort(400, 'Username must be 3–100 letters, numbers, or . _ @ - characters.');
        }
        $password = $body['password'] ?? null;
        if (! is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 200) {
            abort(400, 'Password must be 12–200 characters.');
        }
        $role = $this->role($body['role'] ?? null);
        if (! is_bool($body['active'] ?? null)) {
            abort(400, 'Choose whether this account is active.');
        }
        try {
            $id = DB::table('users')->insertGetId([
                'username' => $username, 'name' => $name, 'role' => $role, 'patient_id' => null,
                'password_hash' => Hash::make($password), 'active' => $body['active'] ? 1 : 0,
                'created_at' => Check::isoNow(),
            ]);
        } catch (QueryException $e) {
            if (Check::isDuplicate($e)) {
                abort(409, 'That username is already in use.');
            }
            throw $e;
        }
        return response()->json(['user' => [
            'id' => (int) $id, 'username' => $username, 'name' => $name, 'role' => $role,
            'active' => $body['active'], 'patientId' => null,
        ]], 201);
    }

    public function update(Request $request, string $id)
    {
        $actor = $this->actor($request);
        $id = Check::routeId($id);
        $body = $request->json()->all();

        $user = DB::transaction(function () use ($id, $body, $actor) {
            $existing = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            if (! $existing) {
                abort(404, 'User not found.');
            }
            $name = Check::text($body['name'] ?? null, 'Name', 200, true);
            $role = $this->role($body['role'] ?? null);
            if (! is_bool($body['active'] ?? null)) {
                abort(400, 'Choose whether this account is active.');
            }
            $active = $body['active'];
            if ((int) $existing->id === (int) $actor['id'] && ($role !== $existing->role || ! $active)) {
                abort(400, 'You cannot change your own role or deactivate your own account.');
            }
            DB::table('users')->where('id', $id)->update([
                'name' => $name, 'role' => $role, 'patient_id' => null, 'active' => $active ? 1 : 0,
            ]);
            // Node also deletes the user's rows in `sessions`. Laravel keeps sessions in files; RequireStaff
            // re-reads the role and the active flag from the database on every request, so the change applies at once.
            return [
                'id' => (int) $existing->id, 'username' => $existing->username, 'name' => $name,
                'role' => $role, 'active' => $active, 'patientId' => null,
            ];
        });
        return response()->json(['user' => $user]);
    }

    public function deactivate(Request $request, string $id)
    {
        $actor = $this->actor($request);
        $id = Check::routeId($id);
        if ($id === (int) $actor['id']) {
            abort(400, 'You cannot deactivate your own account.');
        }
        $user = DB::transaction(function () use ($id) {
            if (! DB::table('users')->where('id', $id)->lockForUpdate()->exists()) {
                abort(404, 'User not found.');
            }
            DB::table('users')->where('id', $id)->update(['active' => 0]);
            return DB::table('users')->where('id', $id)
                ->first(['id', 'username', 'name', 'role', 'active', 'patient_id']);
        });
        return response()->json(['user' => $this->publicUser($user)]);
    }
}
