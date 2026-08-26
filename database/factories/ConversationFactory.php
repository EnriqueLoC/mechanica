<?php

namespace Database\Factories;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workshop_id' => Workshop::factory(),
            'customer_id' => null,
            'phone' => fake()->numerify('##########'),
            'state' => ConversationState::Idle,
            'context' => [],
            'status' => 'active',
            'current_flow' => null,
            'current_step' => null,
            'last_message_at' => now(),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'workshop_id' => $customer->workshop_id,
            'customer_id' => $customer->id,
            'phone' => $customer->phone,
        ]);
    }
}
