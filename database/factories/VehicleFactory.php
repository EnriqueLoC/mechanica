<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workshop_id' => null,
            'customer_id' => null,

            'brand' => fake()->randomElement([
                'Nissan',
                'Toyota',
                'Honda',
                'Chevrolet',
                'Ford',
                'Mazda',
                'Volkswagen',
            ]),

            'model' => fake()->randomElement([
                'Sentra',
                'Altima',
                'Corolla',
                'Civic',
                'Aveo',
                'F-150',
                'Mazda 3',
                'Jetta',
            ]),

            'year' => fake()->numberBetween(2005, 2026),
            'plates' => strtoupper(fake()->bothify('???-####')),
            'vin' => strtoupper(fake()->bothify('1HGBH41JXMN######')),
            'mileage' => fake()->numberBetween(10000, 250000),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'workshop_id' => $customer->workshop_id,
        ]);
    }
}
