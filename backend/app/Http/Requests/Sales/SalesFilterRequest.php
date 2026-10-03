<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\ApiFormRequest;

/** Filtres de période et de référence des ventes. */
class SalesFilterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sku' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'La date de début doit précéder la date de fin : corrigez la plage de dates.',
            'from.date_format' => 'Date de début invalide (format AAAA-MM-JJ).',
            'to.date_format' => 'Date de fin invalide (format AAAA-MM-JJ).',
        ];
    }
}
