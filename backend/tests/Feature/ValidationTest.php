<?php

/*
| Parité avec tests/validation.test.js (v1).
*/

describe('POST /api/auth/register avec des données invalides', function () {
    it('signale un champ obligatoire manquant (email) par un 400 explicite', function () {
        $response = $this->postJson('/api/auth/register', [
            'firstname' => 'Test', 'lastname' => 'SansEmail', 'password' => 'MotDePasse123!',
            'shop_title' => 'Boutique Sans Email', 'contact_number' => '20000000',
        ])->assertStatus(400);

        expect($response->json('message'))->toBe('Champs obligatoires manquants : email.');
    });

    it('liste tous les champs obligatoires manquants', function () {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Champs obligatoires manquants : firstname, lastname, email, password, shop_title, contact_number.');
    });

    it('rejette un email mal formé (400)', function () {
        $this->postJson('/api/auth/register', [
            'firstname' => 'Test', 'lastname' => 'X', 'email' => 'pas-un-email', 'password' => 'MotDePasse123!',
            'shop_title' => 'B1', 'contact_number' => '20000000',
        ])->assertStatus(400)->assertJsonPath('message', 'Adresse email invalide.');
    });

    it('rejette un mot de passe trop court (400)', function () {
        $this->postJson('/api/auth/register', [
            'firstname' => 'Test', 'lastname' => 'X', 'email' => 'court@example.com', 'password' => 'abc',
            'shop_title' => 'B2', 'contact_number' => '20000000',
        ])->assertStatus(400)->assertJsonPath('message', 'Le mot de passe doit contenir au moins 6 caractères.');
    });
});

describe('POST /api/magento/product/add', function () {
    it('refuse l\'accès sans jeton (403)', function () {
        $this->postJson('/api/magento/product/add', ['product' => ['sku' => 'ABC-123', 'name' => 'Produit', 'price' => 10]])
            ->assertForbidden()
            ->assertJsonPath('message', 'Token manquant.');
    });
});
