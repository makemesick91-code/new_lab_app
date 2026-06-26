<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daengtisia Scanner Agent
    |--------------------------------------------------------------------------
    |
    | The KTP scanner is driven by a local desktop agent that runs on the
    | operator's computer (it bridges WIA/TWAIN/SANE which a browser cannot
    | reach directly). The browser talks to it over localhost; this URL is
    | surfaced to the registration UI for the health-check / scan calls.
    |
    | NOTE: this is the URL the *browser on the operator machine* calls, so it
    | must point at localhost on that machine, not at the application server.
    |
    */
    'agent_url' => env('DMS_SCANNER_AGENT_URL', 'http://127.0.0.1:17661'),

    /*
    |--------------------------------------------------------------------------
    | KTP scan capture defaults (sent to the agent)
    |--------------------------------------------------------------------------
    */
    'capture' => [
        'mode' => 'color',
        'dpi' => 200,
        'max_width' => 1600,
        'quality' => 82,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-side upload / compression policy
    |--------------------------------------------------------------------------
    |
    | KTP text must stay readable, so quality stays in a conservative band and
    | images are never upscaled. Compression uses GD when the extension is
    | available; otherwise the validated bytes are stored unchanged.
    |
    */
    'ktp' => [
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_input_kb' => 6144, // 6 MB safety ceiling on the decoded payload.
        'max_width' => 1600,
        'quality' => 82,
        'temp_token_ttl_minutes' => 60,
    ],

];
