<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WorkshopFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Automotriz',
            'phone' => fake()->numerify('614#######'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),

            'whatsapp_phone_number_id' => null,
            'whatsapp_business_account_id' => null,
            'whatsapp_access_token' => null,

            'timezone' => 'America/Chihuahua',
            'active' => true,
        ];
    }
}
