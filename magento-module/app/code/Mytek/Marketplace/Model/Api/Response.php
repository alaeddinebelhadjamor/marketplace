<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Api;

/** Réponse brute de l'API v2. */
class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    /** Message d'erreur renvoyé par l'API (champ « message » ou première erreur de validation). */
    public function errorMessage(): string
    {
        $data = $this->json();
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $first = reset($data['errors']);
            return is_array($first) ? (string)reset($first) : (string)$first;
        }
        return (string)($data['message'] ?? $data['error'] ?? '');
    }
}
