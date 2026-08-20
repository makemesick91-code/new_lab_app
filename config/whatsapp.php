<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Business Platform (Meta Cloud API)
|--------------------------------------------------------------------------
|
| FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — server-to-server prescription
| delivery. There is no wa.me link, no WhatsApp Web and no browser redirect:
| the application calls the official Cloud API itself.
|
| Everything here is env-driven and OFF by default, so a deployment without
| credentials binds the disabled gateway and opens no socket under any
| circumstance. Secrets are read from the environment only — never committed,
| never logged, never rendered.
|
*/

return [
    // Master switch. While false the disabled gateway is bound and every send
    // attempt fails closed with an operator-readable message.
    'enabled' => (bool) env('WHATSAPP_ENABLED', false),

    // disabled | cloud_api | fake  (fake is for automated tests only)
    'driver' => env('WHATSAPP_DRIVER', 'disabled'),

    'cloud_api' => [
        'base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'timeout_seconds' => (int) env('WHATSAPP_TIMEOUT_SECONDS', 15),
    ],

    // SSRF boundary: the outbound host must match this allowlist exactly.
    // A base_url pointing anywhere else is refused before any request is made.
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WHATSAPP_ALLOWED_HOSTS', 'graph.facebook.com'))
    ))),

    /*
    | Meta rule (verified against the official Cloud API documentation):
    | template messages are the ONLY message type deliverable outside an open
    | 24-hour customer service window. A prescription hand-off is proactive, so
    | it MUST be sent as an approved template — this is never bypassed.
    | Category: utility.
    */
    'prescription_template' => [
        'name' => env('WHATSAPP_PRESCRIPTION_TEMPLATE_NAME'),
        'language' => env('WHATSAPP_PRESCRIPTION_TEMPLATE_LANGUAGE', 'id'),
    ],

    'recipient' => [
        // Indonesian numbers are stored as 08xxxx or +62xxxx; both normalise to
        // the E.164 digits Cloud API expects.
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '62'),
        'min_digits' => 9,
        'max_digits' => 15,
    ],
];
