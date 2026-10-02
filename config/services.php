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
    // 'loop' => [
    //     'api_key' => env('LOOP_API_KEY'),
    // ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'firebase' => [
        'secret' => env('FIREBASE_SECRET'),
        // Set FIREBASE_DISABLED=true to force push notifications into no-op mode.
        'disabled' => env('FIREBASE_DISABLED'),
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/aqdi-test-34027147e050.json')),
        'project_id' => env('FIREBASE_PROJECT_ID', 'aqdi-3d3ee'),
        'database_url' => env('FIREBASE_DATABASE_URL'),
        'database_access_token' => env('FIREBASE_DATABASE_ACCESS_TOKEN'),
        'employees_topic' => env('FIREBASE_EMPLOYEES_TOPIC', 'employees'),
        'users_topic' => env('FIREBASE_USERS_TOPIC', 'users'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', ''),
        'seo_redirect' => env('GOOGLE_SEO_REDIRECT_URI'),
        'seo_frontend_redirect' => env('ADMIN_FRONTEND_URL', env('PAYMENT_FRONTEND_URL', 'http://localhost:3000')),
    ],

    // Dedicated OAuth client for website customer "Sign in with Google" — kept
    // separate from `google` above (which the SEO/Search Console integration
    // uses) so the two never collide.
    'google_login' => [
        'client_id' => env('GOOGLE_LOGIN_CLIENT_ID'),
        'client_secret' => env('GOOGLE_LOGIN_CLIENT_SECRET'),
    ],

    'apple' => [
        // Apple "Sign in with Apple": client_id is the Services ID; client_secret
        // is the JWT generated from your Apple key (.p8). Requires the
        // socialiteproviders/apple package. Redirect is set at runtime.
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI', ''),
    ],

    'moyasar' => [
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com'),
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'currency' => env('MOYASAR_CURRENCY', 'SAR'),
        // Hosted invoice / payment page language. Arabic by default.
        'locale' => env('MOYASAR_LOCALE', 'ar'),
        // Payments driver: 'moyasar' (real gateway) or 'test' (simulated charge).
        // Falls back to test-mode automatically when no secret key is present, so a
        // fresh install completes the payment flow end-to-end without real keys.
        'driver' => env('PAYMENTS_DRIVER'),
        // Force simulation even when a secret key exists (e.g. staging smoke tests).
        'test_mode' => env('MOYASAR_TEST_MODE'),
        'payment_frontend_url' => rtrim((string) env('PAYMENT_FRONTEND_URL', 'http://localhost:3000'), '/'),
        'payment_success_url_template' => env('PAYMENT_SUCCESS_URL_TEMPLATE'),
        'payment_error_url_template' => env('PAYMENT_ERROR_URL_TEMPLATE'),
        // Optional deep-link / universal-link templates for the mobile app only.
        'payment_app_success_url_template' => env('PAYMENT_APP_SUCCESS_URL_TEMPLATE'),
        'payment_app_error_url_template' => env('PAYMENT_APP_ERROR_URL_TEMPLATE'),
    ],

    'taqnyat' => [
        'bearer' => env('TAQNYAT_BEARER'),
        'sender' => env('TAQNYAT_SENDER', 'AqdiCo'),
        'sms_id' => env('TAQNYAT_SMS_ID', '25489'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_PHONE'),
    ],

];
