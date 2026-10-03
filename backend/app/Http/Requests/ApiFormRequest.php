<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base des requêtes de l'API.
 *
 * La v1 répond 400 Bad Request (et non 422) aux données invalides, avec un
 * message lisible : ce comportement est conservé pour la parité.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $this->summaryMessage($validator),
            'errors' => $validator->errors(),
        ], 400));
    }

    /** Message principal renvoyé au client. */
    protected function summaryMessage(Validator $validator): string
    {
        return (string) $validator->errors()->first();
    }

    /**
     * Liste des champs en échec sur la règle « required », dans l'ordre donné.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    protected function missingFields(Validator $validator, array $fields): array
    {
        $failed = $validator->failed();

        return array_values(array_filter(
            $fields,
            fn (string $f) => isset($failed[$f]['Required']) || isset($failed[$f]['Filled'])
        ));
    }
}
