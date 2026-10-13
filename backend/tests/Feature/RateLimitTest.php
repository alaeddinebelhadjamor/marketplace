<?php

use App\Models\Seller;

// La limite de 180 requêtes par minute s'applique à chaque vendeur, pas à
// l'adresse IP : deux vendeurs derrière la même adresse ont chacun leur compteur.
it('compte les requêtes séparément pour deux vendeurs de même adresse IP', function () {
    [$a, $b] = Seller::factory()->count(2)->create();
    $tokenA = $a->createToken('api')->plainTextToken;
    $tokenB = $b->createToken('api')->plainTextToken;

    $first = $this->withToken($tokenA)->getJson('/api/profile');
    $second = $this->withToken($tokenA)->getJson('/api/profile');
    app('auth')->forgetGuards(); // nouvelle requête : le garde ne garde pas le vendeur A en mémoire
    $other = $this->withToken($tokenB)->getJson('/api/profile');

    $first->assertOk()->assertHeader('X-RateLimit-Remaining', '179');
    $second->assertOk()->assertHeader('X-RateLimit-Remaining', '178');
    $other->assertOk()->assertHeader('X-RateLimit-Remaining', '179');
});
