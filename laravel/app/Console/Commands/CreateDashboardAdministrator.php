<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateDashboardAdministrator extends Command
{
    protected $signature = 'dashboard:admin:create';

    protected $description = 'Create or promote a FinOps dashboard administrator account';

    public function handle(): int
    {
        $name = (string) $this->ask('Administrator name');
        $email = strtolower((string) $this->ask('Administrator email'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'role' => 'administrator', 'is_active' => true],
        );

        $this->info("Administrator account ready: {$user->email}");
        $this->line('Sign in at /login.');

        return self::SUCCESS;
    }
}
