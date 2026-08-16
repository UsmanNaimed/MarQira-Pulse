<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The dashboard SPA (app.marqira.com) and the API (api.marqira.com) live on
    | different sub-domains, so browser requests are cross-origin. Because the
    | dashboard authenticates with Sanctum's stateful (cookie) session, we must
    | allow credentials and echo back the exact allowed origin(s). Never use "*"
    | together with supports_credentials — browsers reject that combination.
    |
    | Allowed origins come from the CORS_ALLOWED_ORIGINS env var (comma
    | separated) so no dashboard URL is hard-coded here.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
