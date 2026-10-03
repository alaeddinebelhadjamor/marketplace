<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/** Connexion d'un vendeur : identifiant = email ou nom de boutique. */
class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Identifiant et mot de passe obligatoires.',
            'password.required' => 'Identifiant et mot de passe obligatoires.',
        ];
    }

    /** Clé de limitation de débit : identifiant + adresse IP. */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->input('identifier')).'|'.$this->ip();
    }
}
