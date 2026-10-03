<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Inscription d'un vendeur (cas d'utilisation « S'inscrire », tab. 2.2).
 *
 * Seuls les champs listés ici sont retenus (liste blanche) : status, seller_id
 * ou password_hash envoyés par le client sont ignorés.
 */
class RegisterRequest extends ApiFormRequest
{
    public const REQUIRED = ['firstname', 'lastname', 'email', 'password', 'shop_title', 'contact_number'];

    // Même motif que la v1.
    private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    public function rules(): array
    {
        return [
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'regex:'.self::EMAIL_PATTERN],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'shop_title' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'has_patent' => ['nullable', 'boolean'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.regex' => 'Adresse email invalide.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
        ];
    }

    protected function summaryMessage(Validator $validator): string
    {
        $missing = $this->missingFields($validator, self::REQUIRED);
        if ($missing !== []) {
            return 'Champs obligatoires manquants : '.implode(', ', $missing).'.';
        }

        return parent::summaryMessage($validator);
    }

    /** Données nettoyées et limitées à la liste blanche. */
    public function sellerAttributes(): array
    {
        $data = $this->safe()->except(['password']);
        $data['has_patent'] = $this->boolean('has_patent') ? 1 : 0;

        return $data;
    }
}
