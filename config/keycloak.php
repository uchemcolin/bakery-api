<?php

return [

    'keycloak' => [
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realm' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'timeout' => 5,
        'public_key'=>env('KEYCLOAK_REALM_PUBLIC_KEY', null),
        'token_encryption_algorithm'=>env('KEYCLOAK_TOKEN_ENCRYPTION_ALGORITHM', 'RS256'),
        'leeway'=>env('KEYCLOAK_LEEWAY', 0),
    ],

    'user_provider' => [
        'identifier_column' => 'keycloak_id',
        'token_claim' => 'sub',
    ],

    'cache' => [
        'enabled' => true,
        'store' => 'default',
        'prefix' => 'keycloak_guard',
        'ttl' => 300,
        'negative_ttl' => 10,
        'respect_token_exp' => true,
    ],
];
