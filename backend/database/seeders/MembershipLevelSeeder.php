<?php

namespace Database\Seeders;

use App\Models\MembershipLevel;
use Illuminate\Database\Seeder;

class MembershipLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $levels = [
            [
                'name' => 'Free',
                'weekly_limit' => 1,
                'priority' => 0,
                'default_duration_days' => null, // No expiry for free tier
            ],
            [
                'name' => 'Silver',
                'weekly_limit' => 3,
                'priority' => 0,
                'default_duration_days' => 30, // 30 days
            ],
            [
                'name' => 'Gold',
                'weekly_limit' => null, // unlimited
                'priority' => 0,
                'default_duration_days' => 90, // 90 days (3 months)
            ],
            [
                'name' => 'Platinum',
                'weekly_limit' => null, // unlimited
                'priority' => 1, // highest priority
                'default_duration_days' => 365, // 365 days (1 year)
            ],
        ];

        foreach ($levels as $level) {
            MembershipLevel::updateOrCreate(
                ['name' => $level['name']], // Find by name
                $level // Update or create with these values
            );
        }
    }
}




