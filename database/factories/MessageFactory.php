<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'whatsapp_message_id' => null,
            'direction' => 'incoming',
            'type' => 'text',
            'content' => fake()->sentence(),
            'status' => 'received',
            'metadata' => null,
            'sent_at' => now(),
        ];
    }
}
