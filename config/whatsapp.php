<?php

return [
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', ''),
    'app_secret' => env('WHATSAPP_APP_SECRET', ''),

    'graph_api' => [
        'base_url' => env('WHATSAPP_GRAPH_API_BASE_URL', 'https://graph.facebook.com'),
        'version' => env('WHATSAPP_GRAPH_API_VERSION', 'v21.0'),
    ],

    'http' => [
        'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('WHATSAPP_HTTP_CONNECT_TIMEOUT', 5),
    ],
];
