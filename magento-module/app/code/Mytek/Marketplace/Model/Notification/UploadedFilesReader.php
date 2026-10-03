<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Notification;

/**
 * Normalise la structure brute de $_FILES pour un champ de fichiers multiples
 * (name="attachments[]") en une liste de {path, name}, en écartant les emplacements vides et
 * les fichiers mal envoyés. Extrait du contrôleur pour rester testable sans dépendre du serveur
 * web (is_uploaded_file() est contourné dans les tests via le paramètre $isUploadedFile).
 */
class UploadedFilesReader
{
    /**
     * @param mixed $raw le tableau renvoyé par Request::getFiles('attachments')
     * @param ?callable(string):bool $isUploadedFile vérification réelle en production (par
     *        défaut is_uploaded_file()) ; remplaçable en test, is_uploaded_file() renvoyant
     *        toujours faux hors contexte HTTP
     * @return array<int, array{path: string, name: string}>
     */
    public static function normalize($raw, ?callable $isUploadedFile = null): array
    {
        $isUploadedFile ??= 'is_uploaded_file';
        if (!is_array($raw) || empty($raw['tmp_name'])) {
            return [];
        }

        $names = (array)$raw['name'];
        $tmpNames = (array)$raw['tmp_name'];
        $errors = (array)($raw['error'] ?? []);

        $files = [];
        foreach ($tmpNames as $i => $path) {
            $error = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK || !is_string($path) || $path === '' || !$isUploadedFile($path)) {
                continue;
            }
            $files[] = ['path' => $path, 'name' => (string)($names[$i] ?? basename($path))];
        }
        return $files;
    }
}
