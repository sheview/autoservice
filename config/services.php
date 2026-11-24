<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Every PDF is made by Gotenberg (HTML -> PDF with Chromium). See App\Modules\Document.
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:3000'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Cloudflare Turnstile, the light CAPTCHA of the public forms. Empty keys (development) = no CAPTCHA.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Single sign-on with Keycloak (OpenID Connect). Empty client id or base url = no SSO button.
    // redirect: one callback on the central domain (with SESSION_DOMAIN shared by the company
    // subdomains), so Keycloak needs a single Valid Redirect URI; empty = the host the login started on.
    'keycloak' => [
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realms' => env('KEYCLOAK_REALM', 'governix'),
        // Make / turn off staff accounts in Keycloak when they are in Governix (KeycloakAdmin).
        'provision' => (bool) env('KEYCLOAK_PROVISION', false),
        // The realm has Organizations turned on (Keycloak 26+): every sign-in asks for the
        // "organization" scope, and companies on their own system (AD/Entra) can sign in.
        'organizations' => (bool) env('KEYCLOAK_ORGANIZATIONS', false),
    ],
];
