<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Exceptions\WhatsApp\WhatsAppConfigurationException;
use App\Models\Workshop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppCloudApiMessageSender implements WhatsAppMessageSender
{
    /**
     * Send a plain text message to WhatsApp Cloud API.
     *
     * @throws WhatsAppConfigurationException
     * @throws WhatsAppApiException
     */
    public function sendText(Workshop $workshop, string $phone, string $message): void
    {
        if (empty($workshop->whatsapp_phone_number_id)) {
            throw new WhatsAppConfigurationException(
                "Workshop [ID: {$workshop->id}] does not have a configured whatsapp_phone_number_id."
            );
        }

        if (empty($workshop->whatsapp_access_token)) {
            throw new WhatsAppConfigurationException(
                "Workshop [ID: {$workshop->id}] does not have a configured whatsapp_access_token."
            );
        }

        $baseUrl = (string) config('whatsapp.graph_api.base_url', 'https://graph.facebook.com');
        $version = (string) config('whatsapp.graph_api.version', '');

        $url = rtrim($baseUrl, '/');
        if ($version !== '') {
            $url .= '/'.trim($version, '/');
        }
        $url .= '/'.$workshop->whatsapp_phone_number_id.'/messages';

        $timeout = (int) config('whatsapp.http.timeout', 10);
        $connectTimeout = (int) config('whatsapp.http.connect_timeout', 5);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'text',
            'text' => [
                'body' => $message,
            ],
        ];

        try {
            $response = Http::withToken($workshop->whatsapp_access_token)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->asJson()
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('WhatsApp Cloud API network or connection error occurred.', [
                'workshop_id' => $workshop->id,
                'phone_number_id' => $workshop->whatsapp_phone_number_id,
                'to' => $phone,
                'error_message' => $e->getMessage(),
            ]);

            throw new WhatsAppApiException(
                message: 'WhatsApp Cloud API connection error: '.$e->getMessage(),
                statusCode: 0,
                previous: $e,
            );
        }

        if ($response->failed()) {
            $body = $response->json();
            $errorData = is_array($body) && isset($body['error']) && is_array($body['error'])
                ? $body['error']
                : [];

            $metaErrorCode = isset($errorData['code']) && is_numeric($errorData['code']) ? (int) $errorData['code'] : null;
            $metaErrorType = isset($errorData['type']) && is_string($errorData['type']) ? $errorData['type'] : null;
            $metaErrorMessage = isset($errorData['message']) && is_string($errorData['message']) ? $errorData['message'] : null;
            $fbtraceId = isset($errorData['fbtrace_id']) && is_string($errorData['fbtrace_id']) ? $errorData['fbtrace_id'] : null;
            $errorDetails = isset($errorData['error_data']['details']) && is_string($errorData['error_data']['details']) ? $errorData['error_data']['details'] : null;

            $exceptionMessage = 'WhatsApp Cloud API error [HTTP '.$response->status().']';
            if ($metaErrorMessage) {
                $exceptionMessage .= ': '.$metaErrorMessage;
            }

            Log::error('WhatsApp Cloud API responded with an error.', [
                'workshop_id' => $workshop->id,
                'phone_number_id' => $workshop->whatsapp_phone_number_id,
                'to' => $phone,
                'status' => $response->status(),
                'meta_code' => $metaErrorCode,
                'meta_type' => $metaErrorType,
                'meta_message' => $metaErrorMessage,
                'fbtrace_id' => $fbtraceId,
            ]);

            throw new WhatsAppApiException(
                message: $exceptionMessage,
                statusCode: $response->status(),
                metaErrorCode: $metaErrorCode,
                metaErrorType: $metaErrorType,
                fbtraceId: $fbtraceId,
                errorDetails: $errorDetails,
            );
        }

        $responseBody = $response->json();
        $returnedMessageId = is_array($responseBody) && isset($responseBody['messages'][0]['id'])
            ? (string) $responseBody['messages'][0]['id']
            : null;

        Log::info('WhatsApp message sent successfully.', [
            'workshop_id' => $workshop->id,
            'phone_number_id' => $workshop->whatsapp_phone_number_id,
            'to' => $phone,
            'status' => $response->status(),
            'whatsapp_message_id' => $returnedMessageId,
        ]);
    }
}
