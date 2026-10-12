<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEMO accounts (password: "password"). They are NOT created in production: set up real
 * accounts there. Agent skills demonstrate the category aware auto-assignment:
 *   agent1 -> Billing, Account | agent2 -> Technical, Bug Report | agent3 -> no skills (generalist)
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Production: demo users were not created.');

            return;
        }

        $accounts = [
            ['Admin User', 'admin@example.com', UserRole::Admin, []],
            ['Agent One', 'agent1@example.com', UserRole::Agent, ['billing', 'account']],
            ['Agent Two', 'agent2@example.com', UserRole::Agent, ['technical', 'bug-report']],
            ['Agent Three', 'agent3@example.com', UserRole::Agent, []],
            ['Customer One', 'customer1@example.com', UserRole::Customer, []],
            ['Customer Two', 'customer2@example.com', UserRole::Customer, []],
        ];

        foreach ($accounts as [$name, $email, $role, $skills]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'role' => $role,
                'is_available' => true,
                'password' => $user->exists ? $user->password : Hash::make('password'),
                'email_verified_at' => now(),
            ])->save();

            $user->categories()->sync(Category::query()->whereIn('slug', $skills)->pluck('id'));
        }
    }
}
