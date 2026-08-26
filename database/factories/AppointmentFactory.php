<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workshop_id' => null,
            'customer_id' => null,
            'vehicle_id' => null,
            'service_id' => null,

            'appointment_date' => fake()->dateTimeBetween(
                'now',
                '+30 days'
            )->format('Y-m-d'),

            'appointment_time' => fake()->randomElement([
                '08:00:00',
                '09:00:00',
                '10:00:00',
                '11:00:00',
                '12:00:00',
                '14:00:00',
                '15:00:00',
                '16:00:00',
                '17:00:00',
            ]),

            'status' => 'pending',

            'customer_notes' => fake()->optional()->sentence(),
            'internal_notes' => null,

            'confirmed_at' => null,
            'cancelled_at' => null,
            'completed_at' => null,
        ];
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn () => [
            'workshop_id' => $vehicle->workshop_id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn () => [
            'service_id' => $service->id,
            'workshop_id' => $service->workshop_id,
        ]);
    }
}
