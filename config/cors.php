<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Booking API inatumika na Web App (Next.js - http://localhost:3000) na
    | baadaye Mobile App (React Native). Kwa kuwa tunatumia Sanctum Bearer
    | tokens (siyo cookies), 'supports_credentials' inabaki false na
    | 'allowed_origins' => ['*'] ni salama hapa.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
