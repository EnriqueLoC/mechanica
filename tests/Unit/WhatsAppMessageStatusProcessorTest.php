<?php

namespace Tests\Unit;

use App\DTOs\WhatsApp\WhatsAppMessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workshop;
use App\Services\WhatsApp\WhatsAppMessageStatusProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMessageStatusProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkshopAndMessage(array $messageAttributes = []): array
    {
        $workshop = Workshop::factory()->create([
            'whatsapp_phone_number_id' => '10987654321',
        ]);

        $conversation = Conversation::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);

        $message = Message::factory()->create(array_merge([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.HBgLMTIzNDU2',
            'direction' => 'outgoing',
            'type' => 'text',
            'content' => 'Mensaje de prueba',
            'status' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'metadata' => ['initial_key' => 'initial_value'],
        ], $messageAttributes));

        return [$workshop, $message, $conversation];
    }

    public function test_updates_sent_status_and_timestamp(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage();

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000000,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000000), $message->sent_at);
        $this->assertNull($message->delivered_at);
        $this->assertNull($message->read_at);
    }

    public function test_updates_delivered_status_and_timestamp(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage(['status' => 'sent']);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000010), $message->delivered_at);
        $this->assertNull($message->read_at);
    }

    public function test_updates_read_status_and_timestamp(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage(['status' => 'delivered']);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'read',
            timestamp: 1780000020,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_updates_failed_status_and_preserves_metadata(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'metadata' => [
                'existing_key' => 'existing_val',
            ],
        ]);

        $errors = [
            [
                'code' => 131026,
                'title' => 'Message undeliverable',
                'message' => 'Message undeliverable to this phone number',
            ],
        ];

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'failed',
            timestamp: 1780000030,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
            errors: $errors,
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('existing_val', $message->metadata['existing_key']);
        $this->assertIsArray($message->metadata['whatsapp']['status_error']);
        $this->assertSame($errors, $message->metadata['whatsapp']['status_error']['errors']);
        $this->assertSame('5216142120003', $message->metadata['whatsapp']['status_error']['recipient_id']);
    }

    public function test_ignores_status_for_unknown_message(): void
    {
        $workshop = Workshop::factory()->create();

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.unknown.999',
            status: 'sent',
            timestamp: 1780000000,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNull($result);
    }

    public function test_ignores_status_for_message_belonging_to_another_workshop(): void
    {
        [$workshopA, $message] = $this->createWorkshopAndMessage();
        $workshopB = Workshop::factory()->create();

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshopB, $status);

        $this->assertNull($result);
        $message->refresh();
        $this->assertNull($message->status);
        $this->assertNull($message->delivered_at);
    }

    public function test_prevents_regression_from_read_to_delivered(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'read',
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000020),
            'read_at' => CarbonImmutable::createFromTimestamp(1780000020),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->delivered_at);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_prevents_regression_from_delivered_to_sent(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'delivered',
            'sent_at' => CarbonImmutable::createFromTimestamp(1780000010),
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000010),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000000,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000010), $message->sent_at);
        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000010), $message->delivered_at);
    }

    public function test_sent_at_monotonic_existing_more_recent_with_older_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::createFromTimestamp(1780000020),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->sent_at);
    }

    public function test_sent_at_monotonic_existing_older_with_more_recent_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::createFromTimestamp(1780000010),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000020,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->sent_at);
    }

    public function test_delivered_at_monotonic_existing_more_recent_with_older_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'delivered',
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000020),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->delivered_at);
    }

    public function test_delivered_at_monotonic_existing_older_with_more_recent_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'delivered',
            'delivered_at' => CarbonImmutable::createFromTimestamp(1780000010),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000020,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->delivered_at);
    }

    public function test_read_at_monotonic_existing_more_recent_with_older_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'read',
            'read_at' => CarbonImmutable::createFromTimestamp(1780000020),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'read',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_read_at_monotonic_existing_older_with_more_recent_incoming(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'read',
            'read_at' => CarbonImmutable::createFromTimestamp(1780000010),
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'read',
            timestamp: 1780000020,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertEquals(CarbonImmutable::createFromTimestamp(1780000020), $message->read_at);
    }

    public function test_transition_failed_to_sent_remains_failed_and_preserves_error_metadata(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'failed',
            'metadata' => [
                'whatsapp' => [
                    'status_error' => [
                        'errors' => [['code' => 131026]],
                        'failed_at' => '2026-08-26T00:00:00+00:00',
                    ],
                ],
            ],
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000000,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertIsArray($message->metadata['whatsapp']['status_error']);
        $this->assertSame(131026, $message->metadata['whatsapp']['status_error']['errors'][0]['code']);
    }

    public function test_transition_failed_to_delivered_remains_failed_and_preserves_error_metadata(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'failed',
            'metadata' => [
                'whatsapp' => [
                    'status_error' => [
                        'errors' => [['code' => 131026]],
                        'failed_at' => '2026-08-26T00:00:00+00:00',
                    ],
                ],
            ],
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'delivered',
            timestamp: 1780000010,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertIsArray($message->metadata['whatsapp']['status_error']);
        $this->assertSame(131026, $message->metadata['whatsapp']['status_error']['errors'][0]['code']);
    }

    public function test_transition_failed_to_read_remains_failed_and_preserves_error_metadata(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'failed',
            'metadata' => [
                'whatsapp' => [
                    'status_error' => [
                        'errors' => [['code' => 131026]],
                        'failed_at' => '2026-08-26T00:00:00+00:00',
                    ],
                ],
            ],
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'read',
            timestamp: 1780000020,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertIsArray($message->metadata['whatsapp']['status_error']);
        $this->assertSame(131026, $message->metadata['whatsapp']['status_error']['errors'][0]['code']);
    }

    public function test_transition_read_to_failed_remains_read_and_does_not_overwrite_status(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'read',
            'read_at' => CarbonImmutable::createFromTimestamp(1780000020),
            'metadata' => ['existing_key' => 'existing_value'],
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'failed',
            timestamp: 1780000030,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
            errors: [['code' => 131026]],
        );

        $processor->process($workshop, $status);
        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertSame('existing_value', $message->metadata['existing_key']);
        $this->assertArrayNotHasKey('whatsapp', $message->metadata);
    }

    public function test_failed_status_is_not_overwritten_by_older_or_subsequent_status(): void
    {
        [$workshop, $message] = $this->createWorkshopAndMessage([
            'status' => 'failed',
        ]);

        $processor = new WhatsAppMessageStatusProcessor;
        $status = new WhatsAppMessageStatus(
            whatsappMessageId: 'wamid.HBgLMTIzNDU2',
            status: 'sent',
            timestamp: 1780000000,
            recipientPhone: '5216142120003',
            phoneNumberId: '10987654321',
        );

        $result = $processor->process($workshop, $status);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertSame('failed', $message->status);
    }
}
