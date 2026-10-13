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

    // Zinnvy Pay: Inventory subscriptions are paid through it (never a provider directly).
    'pay' => [
        'url' => env('PAY_API_URL'),
        'secret_key' => env('PAY_SECRET_KEY'),
        'webhook_secret' => env('PAY_WEBHOOK_SECRET'),
    ],

    // Public URL of the Inventory web app; OAuth callbacks send people back here.
    'frontend_url' => env('FRONTEND_URL'),

    // SMS provider (read through config so it keeps working when config is cached).
    // Online shop (shop.zinnvy.com, or test-shop.zinnvy.com on staging).
    'shop' => [
        'url' => env('SHOP_URL', 'https://shop.zinnvy.com'),
        // Where "Report this shop" lands.
        // Shared with the shop site so it accepts our "refresh your cache" pings.
        'revalidate_secret' => env('SHOP_REVALIDATE_SECRET'),
        'report_emails' => array_filter(explode(',', env('SHOP_REPORT_EMAILS', 'yawsonandrews@gmail.com'))),
    ],

    // Cloudflare Turnstile on the shop's order form. Off until both keys are set.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
    ],

    'sms' => [
        'base_url' => env('SMS_BASE_URL'),
        'app_id' => env('SMS_APP_ID'),
        'app_secret' => env('SMS_APP_SECRET'),
    ],

    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_inventory,read_orders'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    ],

];
