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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Lizy Mart (Shopify) order webhooks — the signing secret shown under Settings → Notifications → Webhooks.
    'shopify' => [
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
    ],

    // Affiliate links: Lizy Mart products open https://lizymart.com (fixed in AffiliateUrlBuilder);
    // Lizyweb services open the service page below as ?slug={slug}.
    'affiliate' => [
        // Product page path on the shop; {slug} = the product's shop handle.
        'product_path' => env('STOREFRONT_PRODUCT_PATH', '/products/{slug}'),
        'service_base_url' => env('AFFILIATE_SERVICE_BASE_URL', 'https://lizyweb.in/Lizyweb/service.html'),
    ],

    // The lizyweb.in website backend forwards enquiries with this key (X-Website-Key header).
    'website' => [
        'api_key' => env('WEBSITE_API_KEY'),
    ],

];
