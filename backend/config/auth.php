<?php

use App\Models\Seller;

return [

    /*
    | Les comptes authentifiés de l'espace vendeur sont les vendeurs de la table
    | existante marketplace_seller (modèle Seller). Il n'y a pas de table users.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'sellers'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'sellers',
        ],
    ],

    'providers' => [
        'sellers' => [
            'driver' => 'eloquent',
            'model' => Seller::class,
        ],
    ],

    'passwords' => [
        'sellers' => [
            'provider' => 'sellers',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
