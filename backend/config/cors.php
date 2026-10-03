<?php

/*
| L'application Vue (http://localhost:5180) appelle l'API (http://localhost:8010)
| avec le cookie de session : les credentials doivent être autorisés pour cette
| seule origine. Aucune URL n'est codée en dur : FRONTEND_URL, éventuellement
| complétée par CORS_EXTRA_ORIGINS (liste séparée par des virgules).
*/

$origins = array_values(array_filter(array_map('trim', array_merge(
    [env('FRONTEND_URL', 'http://localhost:5180')],
    explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Authorization', 'Accept', 'X-Socket-ID', 'x-admin-key'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => true,
];
