<?php

namespace Database\Seeders;

use App\Models\GymSession;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class GymSessionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $today = Carbon::today();
        
        // Create sessions for the next 2 weeks
        $sessions = [
            // Week 1
            ['name' => 'Morning Cardio', 'date' => $today->copy()->addDays(1), 'start_time' => '06:00', 'end_time' => '07:00', 'capacity' => 20],
            ['name' => 'Strength Training', 'date' => $today->copy()->addDays(1), 'start_time' => '10:00', 'end_time' => '11:30', 'capacity' => 15],
            ['name' => 'Yoga Class', 'date' => $today->copy()->addDays(1), 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 25],
            
            ['name' => 'HIIT Workout', 'date' => $today->copy()->addDays(2), 'start_time' => '07:00', 'end_time' => '08:00', 'capacity' => 20],
            ['name' => 'Pilates', 'date' => $today->copy()->addDays(2), 'start_time' => '12:00', 'end_time' => '13:00', 'capacity' => 18],
            ['name' => 'Evening Cardio', 'date' => $today->copy()->addDays(2), 'start_time' => '19:00', 'end_time' => '20:00', 'capacity' => 22],
            
            ['name' => 'CrossFit', 'date' => $today->copy()->addDays(3), 'start_time' => '06:30', 'end_time' => '07:30', 'capacity' => 12],
            ['name' => 'Zumba', 'date' => $today->copy()->addDays(3), 'start_time' => '17:00', 'end_time' => '18:00', 'capacity' => 30],
            
            ['name' => 'Morning Cardio', 'date' => $today->copy()->addDays(4), 'start_time' => '06:00', 'end_time' => '07:00', 'capacity' => 20],
            ['name' => 'Strength Training', 'date' => $today->copy()->addDays(4), 'start_time' => '10:00', 'end_time' => '11:30', 'capacity' => 15],
            
            ['name' => 'HIIT Workout', 'date' => $today->copy()->addDays(5), 'start_time' => '07:00', 'end_time' => '08:00', 'capacity' => 20],
            ['name' => 'Yoga Class', 'date' => $today->copy()->addDays(5), 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 25],
            
            // Week 2
            ['name' => 'Morning Cardio', 'date' => $today->copy()->addDays(8), 'start_time' => '06:00', 'end_time' => '07:00', 'capacity' => 20],
            ['name' => 'Strength Training', 'date' => $today->copy()->addDays(8), 'start_time' => '10:00', 'end_time' => '11:30', 'capacity' => 15],
            ['name' => 'Yoga Class', 'date' => $today->copy()->addDays(8), 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 25],
            
            ['name' => 'HIIT Workout', 'date' => $today->copy()->addDays(9), 'start_time' => '07:00', 'end_time' => '08:00', 'capacity' => 20],
            ['name' => 'Pilates', 'date' => $today->copy()->addDays(9), 'start_time' => '12:00', 'end_time' => '13:00', 'capacity' => 18],
            
            ['name' => 'CrossFit', 'date' => $today->copy()->addDays(10), 'start_time' => '06:30', 'end_time' => '07:30', 'capacity' => 12],
            ['name' => 'Zumba', 'date' => $today->copy()->addDays(10), 'start_time' => '17:00', 'end_time' => '18:00', 'capacity' => 30],
        ];

        foreach ($sessions as $session) {
            GymSession::create([
                'name' => $session['name'],
                'date' => $session['date'],
                'start_time' => $session['start_time'],
                'end_time' => $session['end_time'],
                'capacity' => $session['capacity'],
            ]);
        }
    }
}




