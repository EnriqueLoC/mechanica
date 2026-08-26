<?php

namespace Tests\Unit;

use App\DTOs\WhatsApp\WhatsAppIncomingMessage;
use App\DTOs\WhatsApp\WhatsAppMessageStatus;
use App\Services\WhatsApp\WhatsAppWebhookParser;
use Tests\TestCase;

class WhatsAppWebhookParserTest extends TestCase
{
    public function test_extracts_text_messages_successfully(): void
    {
        $parser = new WhatsAppWebhookParser;

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '10987654321',
                                ],
                                'messages' => [
                                    [
                                        'from' => '5216142120003',
                                        'id' => 'wamid.001',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Hola',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $messages = $parser->extractTextMessages($payload);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(WhatsAppIncomingMessage::class, $messages[0]);
        $this->assertSame('5216142120003', $messages[0]->phone);
        $this->assertSame('Hola', $messages[0]->message);
        $this->assertSame('wamid.001', $messages[0]->whatsappMessageId);
        $this->assertSame('10987654321', $messages[0]->phoneNumberId);
    }

    public function test_extracts_statuses_successfully(): void
    {
        $parser = new WhatsAppWebhookParser;

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '10987654321',
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.status.001',
                                        'status' => 'delivered',
                                        'timestamp' => '1780000010',
                                        'recipient_id' => '5216142120003',
                                    ],
                                    [
                                        'id' => 'wamid.status.002',
                                        'status' => 'failed',
                                        'timestamp' => 1780000020,
                                        'recipient_id' => '5216142120003',
                                        'errors' => [
                                            ['code' => 131026, 'title' => 'Undeliverable'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $statuses = $parser->extractStatuses($payload);

        $this->assertCount(2, $statuses);

        $this->assertInstanceOf(WhatsAppMessageStatus::class, $statuses[0]);
        $this->assertSame('wamid.status.001', $statuses[0]->whatsappMessageId);
        $this->assertSame('delivered', $statuses[0]->status);
        $this->assertSame(1780000010, $statuses[0]->timestamp);
        $this->assertSame('5216142120003', $statuses[0]->recipientPhone);
        $this->assertSame('10987654321', $statuses[0]->phoneNumberId);
        $this->assertNull($statuses[0]->errors);

        $this->assertInstanceOf(WhatsAppMessageStatus::class, $statuses[1]);
        $this->assertSame('wamid.status.002', $statuses[1]->whatsappMessageId);
        $this->assertSame('failed', $statuses[1]->status);
        $this->assertSame(1780000020, $statuses[1]->timestamp);
        $this->assertIsArray($statuses[1]->errors);
        $this->assertSame(131026, $statuses[1]->errors[0]['code']);
    }

    public function test_ignores_malformed_statuses_safely(): void
    {
        $parser = new WhatsAppWebhookParser;

        $this->assertSame([], $parser->extractStatuses([]));
        $this->assertSame([], $parser->extractStatuses(['entry' => 'not_array']));
        $this->assertSame([], $parser->extractStatuses(['entry' => [['changes' => 'invalid']]]));

        // Missing metadata phone_number_id
        $payloadNoPhoneId = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'statuses' => [
                                    [
                                        'id' => 'wamid.001',
                                        'status' => 'sent',
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
        $this->assertSame([], $parser->extractStatuses($payloadNoPhoneId));

        // Missing required status item fields
        $payloadMissingFields = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => ['phone_number_id' => '123'],
                                'statuses' => [
                                    ['id' => 'wamid.001'], // missing status, timestamp, recipient_id
                                    'not_an_array',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->assertSame([], $parser->extractStatuses($payloadMissingFields));
    }
}
