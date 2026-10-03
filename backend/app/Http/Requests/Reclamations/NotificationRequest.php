<?php

namespace App\Http\Requests\Reclamations;

/** Notification envoyée à un vendeur par l'administration. */
class NotificationRequest extends MessageRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'seller_id' => ['required', 'integer', 'exists:marketplace_seller,seller_id'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'seller_id.required' => 'seller_id obligatoire',
            'seller_id.exists' => 'Vendeur introuvable',
        ]);
    }
}
