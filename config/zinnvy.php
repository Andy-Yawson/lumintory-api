<?php

/**
 * "Continue with Zinnvy" — this app as an OAuth *client* of Zinnvy Identity
 * (the separate OAuth2/OIDC provider at accounts.zinnvy.com). Not to be
 * confused with Zinnvy Identity's own config/zinnvy.php, which is the
 * provider side of this same integration.
 */
return [

    'accounts_url' => env('ZINNVY_ACCOUNTS_URL', 'https://accounts.zinnvy.com'),

    'client_id' => env('ZINNVY_CLIENT_ID'),
    'client_secret' => env('ZINNVY_CLIENT_SECRET'),

    // Must exactly match the redirect_uri registered on the Zinnvy Identity
    // OAuth client — this app's own callback route, not the frontend's.
    'redirect_uri' => env('ZINNVY_REDIRECT_URI'),

    // Where the browser lands after the backend finishes the OAuth
    // handshake and mints a short-lived handoff code.
    'frontend_callback_url' => env('ZINNVY_FRONTEND_CALLBACK_URL'),

    'scopes' => 'openid profile email',

];
