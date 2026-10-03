<?php

namespace App\Http\Requests\Reclamations;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

/**
 * Message d'une réclamation avec pièces jointes (multipart).
 * Accepte « attachments[] » (v2) comme « attachments » répété ou unique (v1).
 */
class MessageRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $files = $this->file('attachments');
        if ($files instanceof UploadedFile) {
            $this->files->set('attachments', [$files]);
        }
    }

    public function rules(): array
    {
        $cfg = config('marketplace.attachments');

        return [
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.$cfg['max_files']],
            'attachments.*' => ['file', 'max:'.$cfg['max_kb'], 'extensions:'.implode(',', $cfg['mimes'])],
        ];
    }

    public function messages(): array
    {
        $cfg = config('marketplace.attachments');

        return [
            'message.required' => 'Message is required',
            'attachments.max' => 'Au plus '.$cfg['max_files'].' pièces jointes par message.',
            'attachments.*.max' => 'Chaque pièce jointe doit faire moins de '.intdiv($cfg['max_kb'], 1024).' Mo.',
            'attachments.*.extensions' => 'Type de fichier non accepté.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        // La v1 renvoyait { error: "Message is required" } : on garde la clé « error ».
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'error' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 400));
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values(array_filter((array) $this->file('attachments', []), fn ($f) => $f instanceof UploadedFile));
    }
}
