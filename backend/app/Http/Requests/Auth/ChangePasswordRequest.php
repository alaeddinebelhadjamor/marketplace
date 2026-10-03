<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

class ChangePasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:255'],
            'newPassword' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'newPassword.min' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
        ];
    }

    protected function summaryMessage(Validator $validator): string
    {
        if ($this->missingFields($validator, ['currentPassword', 'newPassword']) !== []) {
            return 'Champs requis manquants.';
        }

        return parent::summaryMessage($validator);
    }
}
