<?php

return [

    /*
    | Where the Next.js portal lives. OAuth callbacks, email verification and
    | password-reset links send the browser back here.
    */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'auth' => [
        // Lifetime of a portal API token, in minutes (Sanctum expiration).
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 60 * 24 * 7),
        'two_factor_challenge_ttl_minutes' => 10,
        'oauth_state_ttl_minutes' => 10,
        'oauth_exchange_ttl_seconds' => 120,
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts' => 5,
    ],

    'sms' => [
        // "darsms" sends for real; "log" writes to the log (local, CI, tests).
        'driver' => env('SMS_DRIVER', 'log'),
        // Platform sender used for OTPs and until a business's own sender ID is approved.
        'default_sender_id' => env('SMS_DEFAULT_SENDER_ID', 'NGUZOPAY'),
        'darsms' => [
            'base_url' => env('DARSMS_BASE_URL', 'https://dev.darsms.co.tz/api/v1'),
            'api_key' => env('DARSMS_API_KEY'),
            // "api_key" sends X-API-Key: <key>; "bearer" sends Authorization: Bearer <key>.
            'auth' => env('DARSMS_AUTH', 'api_key'),
            'timeout' => (int) env('DARSMS_TIMEOUT', 10),
        ],
    ],

    'invitations' => [
        'ttl_days' => 7,
    ],

    'kyc' => [
        'disk' => env('KYC_DISK', 'local'),
        'max_upload_kb' => 10 * 1024,
    ],

    /*
    | Secrets vault (envelope encryption) for provider credentials and other
    | per-business secrets. Keys are base64 32-byte keys, keyed by version.
    | The active version encrypts; every listed version can decrypt, so keys
    | can be rotated without a data migration.
    */
    'vault' => [
        'active_key' => env('VAULT_ACTIVE_KEY', 'v1'),
        'keys' => array_filter([
            'v1' => env('VAULT_KEY_V1'),
            'v2' => env('VAULT_KEY_V2'),
        ]),
    ],
];
