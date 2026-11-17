<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RequestedDashboardUsersSeeder extends Seeder
{
    /**
     * Create or update the specifically requested dashboard accounts.
     * Run explicitly with: php artisan db:seed --class=RequestedDashboardUsersSeeder
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Andika Kuswidyarto',
                'email' => 'andika.kuswidyarto@borneoprima.com',
                'role' => 'administrator',
            ],
            [
                'name' => 'Abdullah Yusup',
                'email' => 'abdullah.yusup@borneoprima.com',
                'role' => 'administrator',
            ],
            [
                'name' => 'Test User',
                'email' => 'test@borneoprima.com',
                'role' => 'viewer',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'is_active' => true,
                    'password' => Hash::make('password'),
                ],
            );
        }
    }
}
