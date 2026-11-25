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
    | Collections (P1). NguzoPay holds one aggregator account per provider;
    | merchants are sub-accounts in our books, not at the provider.
    */
    'payments' => [
        // "mock" works everywhere except production; snippe and azampay need credentials below.
        'providers' => array_values(array_filter(explode(',', (string) env('PAYMENTS_PROVIDERS', 'mock')))),
        'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', 'mock'),
        // Sandbox environments let unverified businesses test collections.
        'require_active_business' => (bool) env('PAYMENTS_REQUIRE_ACTIVE_BUSINESS', true),
        'intent_ttl_minutes' => (int) env('PAYMENT_INTENT_TTL_MINUTES', 30),
        // Public HTTPS base that providers call back on: {base}/api/v1/webhooks/{provider}.
        'webhook_base_url' => rtrim((string) env('PAYMENTS_WEBHOOK_URL', env('APP_URL', 'http://localhost')), '/'),

        'mock' => [
            'secret' => env('MOCK_PROVIDER_SECRET', env('APP_KEY')),
        ],

        'snippe' => [
            'base_url' => env('SNIPPE_BASE_URL', 'https://api.snippe.sh'),
            'api_key' => env('SNIPPE_API_KEY'),
            'webhook_secret' => env('SNIPPE_WEBHOOK_SECRET'),
            'timeout' => (int) env('SNIPPE_TIMEOUT', 15),
            // Snippe wants a payer email; used when the customer has none and the business has none.
            'fallback_email' => env('SNIPPE_FALLBACK_EMAIL'),
            // Older Snippe callbacks have no timestamp header and sign the body alone.
            'accept_legacy_signature' => (bool) env('SNIPPE_ACCEPT_LEGACY_SIGNATURE', true),
        ],

        'azampay' => [
            'auth_url' => env('AZAMPAY_AUTH_URL', 'https://authenticator-sandbox.azampay.co.tz'),
            'base_url' => env('AZAMPAY_BASE_URL', 'https://sandbox.azampay.co.tz'),
            'app_name' => env('AZAMPAY_APP_NAME'),
            'client_id' => env('AZAMPAY_CLIENT_ID'),
            'client_secret' => env('AZAMPAY_CLIENT_SECRET'),
            'api_key' => env('AZAMPAY_API_KEY'),
            // Optional pinned callback public key (PEM). Otherwise fetched and cached for 24 h.
            'public_key' => env('AZAMPAY_PUBLIC_KEY'),
            // Azampay's docs disagree on what the callback signature covers; see DEVELOPMENT_PLAN §4.
            'signature_fields' => array_values(array_filter(explode(',', (string) env('AZAMPAY_SIGNATURE_FIELDS', 'utilityref,externalreference,transactionstatus,operator')))),
            // Never true in production: accepts callbacks without a signature.
            'allow_unsigned' => (bool) env('AZAMPAY_ALLOW_UNSIGNED', false),
            'timeout' => (int) env('AZAMPAY_TIMEOUT', 15),
            // Azampay's callback does not report the fee: estimate it until statements arrive (P4).
            'fee_bps' => (int) env('AZAMPAY_FEE_BPS', 0),
        ],

        /*
        | Selcom Checkout API: create order, then USSD wallet push. Requests are
        | HMAC-signed with the API secret. Selcom's webhook is confirmed through
        | order-status before anything is booked (its signature is undocumented).
        */
        'selcom' => [
            'base_url' => env('SELCOM_BASE_URL', 'https://apigw.selcommobile.com'),
            'vendor' => env('SELCOM_VENDOR'),         // till / vendor id, e.g. TILL6…
            'api_key' => env('SELCOM_API_KEY'),
            'api_secret' => env('SELCOM_API_SECRET'),
            // Signs the per-order webhook URL token; falls back to APP_KEY.
            'webhook_secret' => env('SELCOM_WEBHOOK_SECRET'),
            // Selcom requires a buyer email; used when neither the customer nor the business has one.
            'fallback_email' => env('SELCOM_FALLBACK_EMAIL'),
            'expiry_minutes' => (int) env('SELCOM_EXPIRY_MINUTES', 60),
            'timeout' => (int) env('SELCOM_TIMEOUT', 20),
            // Selcom's callback does not report the fee: estimate it until statements arrive (P4).
            'fee_bps' => (int) env('SELCOM_FEE_BPS', 0),
        ],

        /*
        | CRDB "standard bills" biller: CRDB calls us over the VPN to verify a
        | control number and to post the payment. Requests are signed by CRDB
        | (SHA256withRSA); CRDB shares only its public key (.pem).
        */
        'crdb' => [
            'institution_id' => env('CRDB_INSTITUTION_ID'),
            // PEM text (\n for newlines) or a path to the .pem file CRDB sends.
            'public_key' => env('CRDB_PUBLIC_KEY'),
            'public_key_path' => env('CRDB_PUBLIC_KEY_PATH'),
            // CRDB's source addresses on the VPN; empty = no IP check (local only).
            'allowed_ips' => array_values(array_filter(explode(',', (string) env('CRDB_ALLOWED_IPS', '')))),
            // Tells CRDB which NguzoPay collection account to credit (agreed with CRDB).
            'payment_type' => env('CRDB_PAYMENT_TYPE', '1'),
            'fee_bps' => (int) env('CRDB_FEE_BPS', 0),
        ],

        /*
        | NMB "Biller Standard Integration API" (v1.4/1.5): NMB calls us for bill
        | inquiry and payment notification. A separate integration from CRDB:
        | own credentials, own collection account, own wire format (600/649).
        */
        'nmb' => [
            // Static Bearer token agreed with NMB (they derive it from our client id + shared secret).
            'bearer_token' => env('NMB_BEARER_TOKEN'),
            'client_id' => env('NMB_CLIENT_ID'),
            'allowed_ips' => array_values(array_filter(explode(',', (string) env('NMB_ALLOWED_IPS', '')))),
            'collection_account' => env('NMB_COLLECTION_ACCOUNT', ''),
            'product_code' => env('NMB_PRODUCT_CODE', ''),
            'bill_product_code' => env('NMB_BILL_PRODUCT_CODE', '001'),
            // 1 = NMB credits our account before notifying; 2 = after our 600 (649 then means no credit).
            'payment_plan' => (string) env('NMB_PAYMENT_PLAN', '2'),
            // Floor for minAmountToPay on partial bills.
            'min_amount' => (int) env('NMB_MIN_AMOUNT', 1000),
            'bill_expiry_days' => (int) env('NMB_BILL_EXPIRY_DAYS', 365),
            'fee_bps' => (int) env('NMB_FEE_BPS', 0),
        ],
    ],

    /*
    | NguzoPay's fee, absorbed by the merchant (D-015). Basis points of the
    | amount plus a fixed TZS amount; a business can override both.
    */
    'pricing' => [
        'fee_bps' => (int) env('NGUZOPAY_FEE_BPS', 0),
        'fee_fixed' => (int) env('NGUZOPAY_FEE_FIXED', 0),
    ],

    // Withdrawals (D-027): paid by NGUZO finance until the payout rails (P3).
    'payouts' => [
        'min_amount' => (int) env('PAYOUT_MIN_AMOUNT', 1000),
        'fee_fixed' => (int) env('PAYOUT_FEE_FIXED', 0),
    ],

    // T+0 sweep (D-016): payments up to the daily cut-off settle the same day.
    'settlement' => [
        'cutoff' => env('SETTLEMENT_CUTOFF', '15:00'),
        'timezone' => env('SETTLEMENT_TIMEZONE', 'Africa/Dar_es_Salaam'),
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
