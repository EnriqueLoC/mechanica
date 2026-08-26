<?php

namespace Database\Factories;

use App\Models\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workshop_id' => Workshop::factory(),

            'name' => fake()->randomElement([
                'Cambio de aceite',
                'Afinación',
                'Diagnóstico',
                'Cambio de frenos',
                'Suspensión',
                'Servicio de transmisión',
                'Diagnóstico computarizado',
            ]),

            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 500, 5000),
            'estimated_minutes' => fake()->randomElement([
                30,
                60,
                90,
                120,
                180,
            ]),
            'active' => true,
        ];
    }
}
