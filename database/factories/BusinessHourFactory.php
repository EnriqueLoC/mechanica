<?php

namespace Database\Factories;

use App\Models\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessHourFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workshop_id' => Workshop::factory(),
            'day_of_week' => fake()->numberBetween(1, 6),
            'opening_time' => '08:00',
            'closing_time' => '18:00',
            'closed' => false,
        ];
    }
}
