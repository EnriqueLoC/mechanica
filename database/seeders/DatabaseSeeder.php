<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $workshop = Workshop::factory()->create([
            'name' => 'Taller Demo Chihuahua',
            'phone' => '6141234567',
            'email' => 'demo@tallerdemo.test',
            'address' => 'Chihuahua, Chihuahua',
        ]);

        // Servicios
        $services = Service::factory()
            ->count(7)
            ->create([
                'workshop_id' => $workshop->id,
            ]);

        // Horarios
        for ($day = 1; $day <= 6; $day++) {
            BusinessHour::create([
                'workshop_id' => $workshop->id,
                'day_of_week' => $day,
                'opening_time' => '08:00',
                'closing_time' => '18:00',
                'closed' => false,
            ]);
        }

        // Domingo cerrado
        BusinessHour::create([
            'workshop_id' => $workshop->id,
            'day_of_week' => 0,
            'opening_time' => null,
            'closing_time' => null,
            'closed' => true,
        ]);

        // Clientes
        Customer::factory()
            ->count(10)
            ->create([
                'workshop_id' => $workshop->id,
            ])
            ->each(function (Customer $customer) use ($services) {

                // 1-2 vehículos por cliente
                Vehicle::factory()
                    ->count(rand(1, 2))
                    ->forCustomer($customer)
                    ->create();

                // Algunas citas
                if (rand(0, 1)) {
                    $vehicle = $customer->vehicles()->inRandomOrder()->first();
                    $service = $services->random();

                    Appointment::factory()
                        ->forVehicle($vehicle)
                        ->create([
                            'service_id' => $service->id,
                        ]);
                }
            });
    }
}
