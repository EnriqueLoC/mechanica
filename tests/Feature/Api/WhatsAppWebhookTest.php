<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationState;
use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\WhatsApp\WhatsAppMessageSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        config(['whatsapp.webhook_verify_token' => 'my_secret_verify_token']);
        config(['whatsapp.app_secret' => '']);
    }

    private function createWorkshop(array $attributes = []): Workshop
    {
        $workshop = Workshop::factory()->create(array_merge([
            'name' => 'Taller WhatsApp Test',
            'phone' => '6142120000',
            'whatsapp_phone_number_id' => 'PHONE_NUMBER_ID_12345',
            'whatsapp_business_account_id' => 'WABA_ID_99999',
            'whatsapp_access_token' => 'fake_access_token',
            'timezone' => 'America/Chihuahua',
            'active' => true,
            'appointment_capacity' => 1,
        ], $attributes));

        for ($day = 1; $day <= 6; $day++) {
            BusinessHour::factory()->create([
                'workshop_id' => $workshop->id,
                'day_of_week' => $day,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'closed' => false,
            ]);
        }

        BusinessHour::factory()->create([
            'workshop_id' => $workshop->id,
            'day_of_week' => 0,
            'opening_time' => '08:00:00',
            'closing_time' => '18:00:00',
            'closed' => true,
        ]);

        return $workshop;
    }

    private function makeTextMessagePayload(
        string $phoneNumberId,
        string $fromPhone,
        string $messageText,
        string $whatsappMessageId = 'wamid.HBgLMTIzNDU2'
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Test User',
                                        ],
                                        'wa_id' => $fromPhone,
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => $fromPhone,
                                        'id' => $whatsappMessageId,
                                        'timestamp' => '1780000000',
                                        'text' => [
                                            'body' => $messageText,
                                        ],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function makeStatusPayload(
        string $phoneNumberId,
        string $whatsappMessageId,
        string $status,
        string $recipientId = '5216142120003',
        int $timestamp = 1780000000,
        ?array $errors = null
    ): array {
        $statusItem = [
            'id' => $whatsappMessageId,
            'status' => $status,
            'timestamp' => (string) $timestamp,
            'recipient_id' => $recipientId,
        ];

        if ($errors !== null) {
            $statusItem['errors'] = $errors;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'statuses' => [
                                    $statusItem,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ==========================================
    // GET Verification Tests
    // ==========================================

    public function test_verification_succeeds_with_valid_token_and_mode(): void
    {
        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=my_secret_verify_token&hub.challenge=1158201444');

        $response->assertStatus(200)
            ->assertSee('1158201444', false)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_verification_succeeds_with_underscored_params(): void
    {
        $response = $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=my_secret_verify_token&hub_challenge=987654321');

        $response->assertStatus(200)
            ->assertSee('987654321', false);
    }

    public function test_verification_fails_with_invalid_token(): void
    {
        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=1158201444');

        $response->assertStatus(403);
    }

    public function test_verification_fails_with_invalid_mode(): void
    {
        $response = $this->get('/api/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=my_secret_verify_token&hub.challenge=1158201444');

        $response->assertStatus(403);
    }

    public function test_verification_fails_when_no_token_configured(): void
    {
        config(['whatsapp.webhook_verify_token' => '']);

        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=any_token&hub.challenge=1158201444');

        $response->assertStatus(403);
    }

    // ==========================================
    // POST Webhook Tests
    // ==========================================

    public function test_valid_text_message_is_processed_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $payload = $this->makeTextMessagePayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            fromPhone: '5216142120003',
            messageText: 'cita',
            whatsappMessageId: 'wamid.test.webhook.001'
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.test.webhook.001',
            'direction' => 'incoming',
            'content' => 'cita',
        ]);

        $this->assertDatabaseHas('conversations', [
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
            'state' => ConversationState::SelectingService->value,
        ]);
    }

    public function test_unknown_phone_number_id_does_not_process_and_returns_200(): void
    {
        $this->createWorkshop(['whatsapp_phone_number_id' => 'KNOWN_PHONE_NUMBER_ID']);

        $payload = $this->makeTextMessagePayload(
            phoneNumberId: 'UNKNOWN_PHONE_NUMBER_ID',
            fromPhone: '5216142120003',
            messageText: 'cita',
            whatsappMessageId: 'wamid.unknown.001'
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_sent_status_event_updates_message_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.status.sent.001',
            'direction' => 'outgoing',
            'status' => null,
            'sent_at' => null,
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.status.sent.001',
            status: 'sent',
            timestamp: 1780000000,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000000), $message->sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_delivered_status_event_updates_message_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.status.deliv.001',
            'direction' => 'outgoing',
            'status' => 'sent',
            'sent_at' => CarbonImmutable::createFromTimestamp(1780000000),
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.status.deliv.001',
            status: 'delivered',
            timestamp: 1780000010,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000010), $message->delivered_at);
    }

    public function test_read_status_event_updates_message_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.status.read.001',
            'direction' => 'outgoing',
            'status' => 'delivered',
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000010),
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.status.read.001',
            status: 'read',
            timestamp: 1780000020,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_failed_status_event_updates_message_and_metadata_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.status.fail.001',
            'direction' => 'outgoing',
            'metadata' => ['test_key' => 'test_val'],
        ]);

        $errors = [
            [
                'code' => 131026,
                'title' => 'Message undeliverable',
                'message' => 'Message undeliverable',
            ],
        ];

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.status.fail.001',
            status: 'failed',
            recipientId: '5216142120003',
            timestamp: 1780000030,
            errors: $errors,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('test_val', $message->metadata['test_key']);
        $this->assertIsArray($message->metadata['whatsapp']['status_error']);
        $this->assertSame($errors, $message->metadata['whatsapp']['status_error']['errors']);
    }

    public function test_status_for_unknown_message_returns_200_and_does_not_modify_database(): void
    {
        $workshop = $this->createWorkshop();

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.unknown.001',
            status: 'delivered',
            timestamp: 1780000000,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_status_for_message_belonging_to_another_workshop_is_ignored(): void
    {
        $workshopA = $this->createWorkshop(['whatsapp_phone_number_id' => 'PHONE_NUMBER_A']);
        $workshopB = $this->createWorkshop(['whatsapp_phone_number_id' => 'PHONE_NUMBER_B']);

        $conversationB = Conversation::factory()->create([
            'workshop_id' => $workshopB->id,
            'phone' => '6142120003',
        ]);
        $messageB = Message::factory()->create([
            'conversation_id' => $conversationB->id,
            'whatsapp_message_id' => 'wamid.msg.workshop.b',
            'direction' => 'outgoing',
            'status' => null,
        ]);

        // Status arriving with Workshop A's phone_number_id attempting to update message belonging to Workshop B
        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_A',
            whatsappMessageId: 'wamid.msg.workshop.b',
            status: 'delivered',
            timestamp: 1780000000,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $messageB->refresh();
        $this->assertNull($messageB->status);
        $this->assertNull($messageB->delivered_at);
    }

    public function test_multiple_statuses_in_single_payload_are_all_processed(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);

        $message1 = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.batch.001',
            'status' => null,
        ]);
        $message2 = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.batch.002',
            'status' => null,
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.batch.001',
                                        'status' => 'sent',
                                        'timestamp' => '1780000000',
                                        'recipient_id' => '5216142120003',
                                    ],
                                    [
                                        'id' => 'wamid.batch.002',
                                        'status' => 'delivered',
                                        'timestamp' => '1780000010',
                                        'recipient_id' => '5216142120003',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $message1->refresh();
        $message2->refresh();

        $this->assertSame('sent', $message1->status);
        $this->assertSame('delivered', $message2->status);
    }

    public function test_status_regression_protection_in_webhook(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.regr.001',
            'status' => 'read',
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000020),
            'read_at' => CarbonImmutable::createFromTimestamp(1780000020),
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.regr.001',
            status: 'delivered',
            timestamp: 1780000010,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);
        $response->assertOk();

        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->delivered_at);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_delayed_webhook_status_does_not_modify_more_recent_timestamp(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.monotonic.001',
            'status' => 'sent',
            'sent_at' => CarbonImmutable::createFromTimestamp(1780000050),
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.monotonic.001',
            status: 'sent',
            timestamp: 1780000030,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);
        $response->assertOk();

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000050), $message->sent_at);
    }

    public function test_failed_status_is_not_overwritten_by_earlier_status_in_webhook(): void
    {
        $workshop = $this->createWorkshop();
        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.failed.001',
            'status' => 'failed',
        ]);

        $payload = $this->makeStatusPayload(
            phoneNumberId: 'PHONE_NUMBER_ID_12345',
            whatsappMessageId: 'wamid.failed.001',
            status: 'sent',
            timestamp: 1780000000,
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);
        $response->assertOk();

        $message->refresh();
        $this->assertSame('failed', $message->status);
    }

    public function test_event_without_messages_does_not_process_conversation_and_returns_200(): void
    {
        $this->createWorkshop();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_non_text_message_does_not_process_conversation_and_returns_200(): void
    {
        $this->createWorkshop();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                                'messages' => [
                                    [
                                        'from' => '5216142120003',
                                        'id' => 'wamid.image.001',
                                        'timestamp' => '1780000000',
                                        'type' => 'image',
                                        'image' => [
                                            'id' => 'img_12345',
                                            'mime_type' => 'image/jpeg',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_malformed_or_empty_payload_does_not_throw_500(): void
    {
        $response1 = $this->postJson('/api/webhooks/whatsapp', []);
        $response1->assertStatus(200)->assertJson(['status' => 'ignored']);

        $response2 = $this->postJson('/api/webhooks/whatsapp', ['entry' => 'invalid_string']);
        $response2->assertStatus(200)->assertJson(['status' => 'ignored']);

        $response3 = $this->postJson('/api/webhooks/whatsapp', ['entry' => [['changes' => null]]]);
        $response3->assertStatus(200)->assertJson(['status' => 'ignored']);
    }

    public function test_duplicate_webhook_payload_is_handled_idempotently(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);
        Vehicle::factory()->create([
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
        ]);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        // Flujo: 1. cita
        $this->postJson('/api/webhooks/whatsapp', $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'cita',
            'wamid.flow.001'
        ))->assertOk();

        // 2. servicio 1
        $this->postJson('/api/webhooks/whatsapp', $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            '1',
            'wamid.flow.002'
        ))->assertOk();

        // 3. vehículo 1
        $this->postJson('/api/webhooks/whatsapp', $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            '1',
            'wamid.flow.003'
        ))->assertOk();

        // 4. fecha
        $this->postJson('/api/webhooks/whatsapp', $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            $futureDate->format('d/m/Y'),
            'wamid.flow.004'
        ))->assertOk();

        // 5. horario 1
        $this->postJson('/api/webhooks/whatsapp', $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            '1',
            'wamid.flow.005'
        ))->assertOk();

        // 6. confirmación con sí
        $confirmationPayload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'sí',
            'wamid.flow.006'
        );

        $res6 = $this->postJson('/api/webhooks/whatsapp', $confirmationPayload);
        $res6->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(1, Appointment::count());
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());

        // 7. Reenviar exactamente el mismo payload de confirmación
        $res7 = $this->postJson('/api/webhooks/whatsapp', $confirmationPayload);
        $res7->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(1, Appointment::count());
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());
    }

    public function test_signature_verification_succeeds_with_valid_signature(): void
    {
        config(['whatsapp.app_secret' => 'test_app_secret_123']);

        $this->createWorkshop();

        $payload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'hola',
            'wamid.sig.001'
        );

        $rawBody = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, 'test_app_secret_123');

        $response = $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawBody
        );

        $response->assertStatus(200);
    }

    public function test_payload_with_multiple_messages_processes_all_text_messages(): void
    {
        $workshop = $this->createWorkshop();
        $customerA = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6141110001',
        ]);
        $customerB = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6141110002',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                                'messages' => [
                                    [
                                        'from' => '5216141110001',
                                        'id' => 'wamid.multi.001',
                                        'timestamp' => '1780000000',
                                        'text' => ['body' => 'cita'],
                                        'type' => 'text',
                                    ],
                                    [
                                        'from' => '5216141110002',
                                        'id' => 'wamid.multi.002',
                                        'timestamp' => '1780000001',
                                        'text' => ['body' => 'cita'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200)->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('messages', ['whatsapp_message_id' => 'wamid.multi.001']);
        $this->assertDatabaseHas('messages', ['whatsapp_message_id' => 'wamid.multi.002']);
        $this->assertSame(2, Message::where('direction', 'incoming')->count());
        $this->assertSame(2, Message::where('direction', 'outgoing')->count());
    }

    public function test_signature_verification_fails_with_invalid_signature(): void
    {
        config(['whatsapp.app_secret' => 'test_app_secret_123']);

        $this->createWorkshop();

        $payload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'hola',
            'wamid.sig.002'
        );

        $rawBody = json_encode($payload);
        $invalidSignature = 'sha256=invalid_hash_signature_value';

        $response = $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $invalidSignature,
            ],
            $rawBody
        );

        $response->assertStatus(403);
    }

    public function test_new_message_calls_message_sender_once(): void
    {
        $workshop = $this->createWorkshop();
        Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $mockSender = $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) use ($workshop) {
            $mock->shouldReceive('sendText')
                ->once()
                ->withArgs(function ($ws, $phone, $message) use ($workshop) {
                    return $ws->id === $workshop->id
                        && $phone === '5216142120003'
                        && str_contains($message, 'Afinación');
                });
        });

        $payload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'cita',
            'wamid.sender.001'
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);
        $response->assertOk();
    }

    public function test_duplicate_message_does_not_call_message_sender(): void
    {
        $workshop = $this->createWorkshop();
        Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $mockSender = $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) {
            // First time it should be called once, duplicate time it should NOT be called again
            $mock->shouldReceive('sendText')
                ->once();
        });

        $payload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'cita',
            'wamid.sender.dup.001'
        );

        // 1st time
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        // 2nd time (duplicate)
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    }

    public function test_status_event_does_not_call_message_sender(): void
    {
        $this->createWorkshop();

        $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendText');
        });

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.HBgLMTIzNDU2',
                                        'status' => 'delivered',
                                        'timestamp' => '1780000000',
                                        'recipient_id' => '5216142120003',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    }

    public function test_non_text_message_does_not_call_message_sender(): void
    {
        $this->createWorkshop();

        $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendText');
        });

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_ID_99999',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '5216141234567',
                                    'phone_number_id' => 'PHONE_NUMBER_ID_12345',
                                ],
                                'messages' => [
                                    [
                                        'from' => '5216142120003',
                                        'id' => 'wamid.image.001',
                                        'timestamp' => '1780000000',
                                        'type' => 'image',
                                        'image' => ['id' => '123456'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    }

    public function test_unknown_workshop_does_not_call_message_sender(): void
    {
        $this->createWorkshop(['whatsapp_phone_number_id' => 'KNOWN_PHONE_NUMBER_ID']);

        $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendText');
        });

        $payload = $this->makeTextMessagePayload(
            'UNKNOWN_PHONE_NUMBER_ID',
            '5216142120003',
            'cita',
            'wamid.unknown.sender'
        );

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    }

    public function test_webhook_handles_sender_exception_gracefully(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $this->mock(WhatsAppMessageSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendText')
                ->once()
                ->andThrow(new WhatsAppApiException('Meta API error', 500));
        });

        $payload = $this->makeTextMessagePayload(
            'PHONE_NUMBER_ID_12345',
            '5216142120003',
            'cita',
            'wamid.sender.err.001'
        );

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertOk()->assertJson(['status' => 'success']);

        // Check that the incoming message and conversation state were preserved despite sender error
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.sender.err.001',
            'direction' => 'incoming',
        ]);
        $this->assertDatabaseHas('conversations', [
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
            'state' => ConversationState::SelectingService->value,
        ]);
    }
}
