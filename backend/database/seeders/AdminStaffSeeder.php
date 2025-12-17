<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminStaffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get membership levels (they should be seeded first)
        $platinumLevel = \App\Models\MembershipLevel::where('name', 'Platinum')->first();
        $goldLevel = \App\Models\MembershipLevel::where('name', 'Gold')->first();

        // Create Super Admin User
        User::updateOrCreate(
            ['email' => 'superadmin@gym.com'],
            [
                'firstname' => 'Super',
                'lastname' => 'Admin',
                'middlename' => null,
                'email' => 'superadmin@gym.com',
                'password' => Hash::make('superadmin123'),
                'role' => 'super_admin',
                'is_verified' => true,
                'email_verified_at' => now(),
                'membership_level_id' => null, // Super Admin doesn't need membership
            ]
        );

        // Create Admin User
        User::updateOrCreate(
            ['email' => 'admin@gym.com'],
            [
                'firstname' => 'Admin',
                'lastname' => 'User',
                'middlename' => null,
                'email' => 'admin@gym.com',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'is_verified' => true,
                'email_verified_at' => now(),
                'membership_level_id' => $platinumLevel ? $platinumLevel->id : null,
            ]
        );

        // Create Staff User
        User::updateOrCreate(
            ['email' => 'staff@gym.com'],
            [
                'firstname' => 'Staff',
                'lastname' => 'User',
                'middlename' => null,
                'email' => 'staff@gym.com',
                'password' => Hash::make('staff123'),
                'role' => 'staff',
                'is_verified' => true,
                'email_verified_at' => now(),
                'membership_level_id' => $goldLevel ? $goldLevel->id : null,
            ]
        );

        $this->command->info('Super Admin, Admin and Staff users created successfully!');
        $this->command->info('Super Admin: superadmin@gym.com / superadmin123');
        $this->command->info('Admin: admin@gym.com / admin123');
        $this->command->info('Staff: staff@gym.com / staff123');
    }
}
