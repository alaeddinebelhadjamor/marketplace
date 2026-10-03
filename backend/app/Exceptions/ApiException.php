<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Erreur métier attendue, rendue telle quelle au client :
 * { "message": "...", ...extra } avec le code HTTP donné.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly array $extra = [],
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge(['message' => $this->getMessage()], $this->extra), $this->status);
    }
}
