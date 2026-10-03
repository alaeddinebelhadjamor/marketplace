<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/** Modification du prix et de la promotion (tab. 2.7). */
class UpdatePriceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'price' => ['nullable', 'required_without:special_price', 'numeric', 'gt:0', 'max:9999999'],
            'special_price' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'special_from_date' => ['nullable', 'date_format:Y-m-d'],
            'special_to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:special_from_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'SKU obligatoire',
            'price.required_without' => 'price ou special_price est obligatoire',
            'special_to_date.after_or_equal' => 'La date de fin de promotion doit suivre la date de début.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $price = $this->input('price');
            $special = $this->input('special_price');
            if (is_numeric($price) && is_numeric($special) && (float) $special >= (float) $price) {
                $validator->errors()->add('special_price', 'Le prix promotionnel doit être inférieur au prix normal.');
            }
        }];
    }

    protected function summaryMessage(Validator $validator): string
    {
        $failed = $validator->failed();
        if (isset($failed['sku']['Required'])) {
            return 'SKU obligatoire';
        }
        if (isset($failed['price']['RequiredWithout'])) {
            return 'price ou special_price est obligatoire';
        }

        return parent::summaryMessage($validator);
    }

    /** Le client a-t-il envoyé le champ special_price (même à null) ? */
    public function touchesPromotion(): bool
    {
        return $this->exists('special_price');
    }
}
