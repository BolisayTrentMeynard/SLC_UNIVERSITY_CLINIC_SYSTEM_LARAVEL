<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Development accounts only (same as the Node seed-users script). */
    public function run(): void
    {
        $accounts = [
            ['username' => 'nurse1',  'name' => 'Default Nurse',  'role' => 'nurse'],
            ['username' => 'doctor1', 'name' => 'Default Doctor', 'role' => 'doctor'],
        ];
        foreach ($accounts as $account) {
            DB::table('users')->insertOrIgnore($account + [
                'patient_id' => null,
                'password_hash' => Hash::make('clinic-dev-2026'),
                'active' => 1,
                'created_at' => now()->toIso8601String(),
            ]);
        }
    }
}
