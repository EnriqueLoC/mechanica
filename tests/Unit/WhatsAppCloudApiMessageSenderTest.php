<?php

namespace Tests\Unit;

use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Exceptions\WhatsApp\WhatsAppConfigurationException;
use App\Models\Workshop;
use App\Services\WhatsApp\WhatsAppCloudApiMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCloudApiMessageSenderTest extends TestCase
{
    private function makeWorkshop(array $attributes = []): Workshop
    {
        return new Workshop(array_merge([
            'id' => 1,
            'name' => 'Taller Demo',
            'whatsapp_phone_number_id' => '10987654321',
            'whatsapp_business_account_id' => 'WABA_12345',
            'whatsapp_access_token' => 'EAAG_SECRET_TOKEN_12345',
            'timezone' => 'America/Chihuahua',
        ], $attributes));
    }

    public function test_sends_text_message_successfully(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [
                    [
                        'input' => '5216142120003',
                        'wa_id' => '5216142120003',
                    ],
                ],
                'messages' => [
                    [
                        'id' => 'wamid.HBgLMTIzNDU2',
                    ],
                ],
            ], 200),
        ]);

        config([
            'whatsapp.graph_api.base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_api.version' => 'v21.0',
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        $sender->sendText($workshop, '5216142120003', 'Hola, tu cita está confirmada.');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v21.0/10987654321/messages'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer EAAG_SECRET_TOKEN_12345')
                && $request->header('Content-Type')[0] === 'application/json'
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '5216142120003'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hola, tu cita está confirmada.';
        });
    }

    public function test_uses_workshop_phone_number_id_in_url(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/PHONE_SPECIFIC_999/messages' => Http::response(['success' => true], 200),
        ]);

        config([
            'whatsapp.graph_api.base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_api.version' => 'v21.0',
        ]);

        $workshop = $this->makeWorkshop([
            'whatsapp_phone_number_id' => 'PHONE_SPECIFIC_999',
        ]);

        $sender = app(WhatsAppCloudApiMessageSender::class);
        $sender->sendText($workshop, '5216142120003', 'Mensaje de prueba');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/PHONE_SPECIFIC_999/messages');
        });
    }

    public function test_uses_workshop_access_token_in_authorization_header(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        $workshop = $this->makeWorkshop([
            'whatsapp_access_token' => 'TOKEN_WORKSHOP_ABC',
        ]);

        $sender = app(WhatsAppCloudApiMessageSender::class);
        $sender->sendText($workshop, '5216142120003', 'Mensaje');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer TOKEN_WORKSHOP_ABC');
        });
    }

    public function test_builds_url_correctly_when_version_is_empty(): void
    {
        Http::fake([
            'https://graph.facebook.com/10987654321/messages' => Http::response(['success' => true], 200),
        ]);

        config([
            'whatsapp.graph_api.base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_api.version' => '',
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);
        $sender->sendText($workshop, '5216142120003', 'Mensaje');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/10987654321/messages';
        });
    }

    public function test_throws_api_exception_on_http_400_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Invalid parameter',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_data' => [
                        'details' => 'The phone number format is invalid.',
                    ],
                    'fbtrace_id' => 'trace_400_abc',
                ],
            ], 400),
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        try {
            $sender->sendText($workshop, '5216142120003', 'Mensaje');
            $this->fail('Expected WhatsAppApiException was not thrown.');
        } catch (WhatsAppApiException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame(100, $e->metaErrorCode);
            $this->assertSame('OAuthException', $e->metaErrorType);
            $this->assertSame('trace_400_abc', $e->fbtraceId);
            $this->assertSame('The phone number format is invalid.', $e->errorDetails);
            $this->assertStringContainsString('Invalid parameter', $e->getMessage());
            $this->assertStringNotContainsString('EAAG_SECRET_TOKEN_12345', $e->getMessage());
        }
    }

    public function test_throws_api_exception_on_http_401_or_403_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Session has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'fbtrace_id' => 'trace_auth_err',
                ],
            ], 401),
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        $this->expectException(WhatsAppApiException::class);
        $this->expectExceptionMessage('WhatsApp Cloud API error [HTTP 401]: Session has expired');

        $sender->sendText($workshop, '5216142120003', 'Mensaje');
    }

    public function test_throws_api_exception_on_http_500_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Internal Server Error',
                    'type' => 'FacebookApiException',
                    'code' => 2,
                ],
            ], 500),
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        $this->expectException(WhatsAppApiException::class);
        $this->expectExceptionMessage('WhatsApp Cloud API error [HTTP 500]: Internal Server Error');

        $sender->sendText($workshop, '5216142120003', 'Mensaje');
    }

    public function test_throws_api_exception_on_connection_timeout(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out after 5000 milliseconds'),
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        try {
            $sender->sendText($workshop, '5216142120003', 'Mensaje');
            $this->fail('Expected WhatsAppApiException was not thrown on timeout.');
        } catch (WhatsAppApiException $e) {
            $this->assertSame(0, $e->statusCode);
            $this->assertStringContainsString('Connection timed out', $e->getMessage());
            $this->assertStringNotContainsString('EAAG_SECRET_TOKEN_12345', $e->getMessage());
        }
    }

    public function test_throws_configuration_exception_when_phone_number_id_is_missing(): void
    {
        $workshop = $this->makeWorkshop([
            'whatsapp_phone_number_id' => null,
        ]);

        $sender = app(WhatsAppCloudApiMessageSender::class);

        $this->expectException(WhatsAppConfigurationException::class);
        $this->expectExceptionMessage('does not have a configured whatsapp_phone_number_id');

        $sender->sendText($workshop, '5216142120003', 'Mensaje');
    }

    public function test_throws_configuration_exception_when_access_token_is_missing(): void
    {
        $workshop = $this->makeWorkshop([
            'whatsapp_access_token' => null,
        ]);

        $sender = app(WhatsAppCloudApiMessageSender::class);

        $this->expectException(WhatsAppConfigurationException::class);
        $this->expectExceptionMessage('does not have a configured whatsapp_access_token');

        $sender->sendText($workshop, '5216142120003', 'Mensaje');
    }

    public function test_never_leaks_access_token_in_exception_with_unstructured_error_response(): void
    {
        Http::fake([
            '*' => Http::response('<html>Bad Gateway</html>', 502),
        ]);

        $workshop = $this->makeWorkshop();
        $sender = app(WhatsAppCloudApiMessageSender::class);

        try {
            $sender->sendText($workshop, '5216142120003', 'Mensaje');
            $this->fail('Expected WhatsAppApiException was not thrown.');
        } catch (WhatsAppApiException $e) {
            $this->assertSame(502, $e->statusCode);
            $this->assertNull($e->metaErrorCode);
            $this->assertStringNotContainsString('EAAG_SECRET_TOKEN_12345', $e->getMessage());
        }
    }
}
