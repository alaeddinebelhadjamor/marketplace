<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\ApiFormRequest;

/** Requête qui désigne un produit par son SKU (corps ou chaîne de requête). */
class SkuRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['sku' => ['required', 'string', 'max:64']];
    }

    public function messages(): array
    {
        return ['sku.required' => 'SKU obligatoire'];
    }

    public function sku(): string
    {
        return (string) $this->input('sku');
    }
}
