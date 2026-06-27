<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@ohmacore.local');
        $password = env('ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Ohmacore Admin'),
                'first_name' => env('ADMIN_FIRST_NAME', 'Ohmacore'),
                'last_name' => env('ADMIN_LAST_NAME', 'Admin'),
                'pseudo' => env('ADMIN_PSEUDO', 'admin'),
                'password' => Hash::make($password),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Admin account seeded for {$email}.");
    }
}
