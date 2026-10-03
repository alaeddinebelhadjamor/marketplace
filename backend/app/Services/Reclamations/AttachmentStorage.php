<?php

namespace App\Services\Reclamations;

use App\Models\ReclamationAttachment;
use App\Models\ReclamationMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stockage des pièces jointes des réclamations.
 *
 * Les fichiers sont privés (storage/app/private/reclamations) : ils ne sont
 * servis qu'après contrôle d'accès. Le chemin enregistré garde le format de la
 * v1 (uploads/reclamations/<nom>) pour que les deux versions lisent la table
 * de la même façon.
 */
class AttachmentStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mpeg' => 'mp3',
    ];

    /** Nom de fichier accepté dans une URL : pas de chemin, pas de « .. ». */
    public const SAFE_FILENAME = '/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/';

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeAll(ReclamationMessage $message, array $files): void
    {
        foreach ($files as $file) {
            $name = $this->filenameFor($file);
            Storage::disk('local')->putFileAs($this->directory(), $file, $name);

            $message->attachments()->create([
                'file_path' => 'uploads/reclamations/'.$name,
                'file_type' => mb_substr((string) $file->getMimeType(), 0, 50),
            ]);
        }
    }

    /**
     * Chemin absolu d'un fichier, cherché dans le stockage v2 puis, en lecture
     * seule, dans le dossier de la v1 s'il est configuré. null si introuvable.
     */
    public function locate(string $filename): ?string
    {
        if (! preg_match(self::SAFE_FILENAME, $filename)) {
            return null;
        }

        $relative = $this->directory().'/'.$filename;
        if (Storage::disk('local')->exists($relative)) {
            return Storage::disk('local')->path($relative);
        }

        $legacy = config('marketplace.attachments.legacy_directory');
        if ($legacy) {
            $base = realpath($legacy);
            $path = $base ? realpath($base.DIRECTORY_SEPARATOR.$filename) : false;
            if ($path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Pièce jointe enregistrée en base pour ce nom de fichier. */
    public function findRecord(string $filename): ?ReclamationAttachment
    {
        if (! preg_match(self::SAFE_FILENAME, $filename)) {
            return null;
        }

        // Filtre large en SQL (fin de chemin), puis comparaison exacte du nom en PHP :
        // le séparateur enregistré peut être « / » ou « \ » selon la version qui a écrit.
        return ReclamationAttachment::query()
            ->with('message.reclamation')
            ->where('file_path', 'like', '%'.$filename)
            ->get()
            ->first(fn (ReclamationAttachment $a) => $a->filename() === $filename);
    }

    public function mimeOf(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = array_flip(self::MIME_EXTENSIONS);
        $map['jpeg'] = 'image/jpeg';
        $map['wav'] = 'audio/wav';

        return $map[$ext] ?? 'application/octet-stream';
    }

    private function filenameFor(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = config('marketplace.attachments.mimes');
        if (! in_array($ext, $allowed, true)) {
            $ext = self::MIME_EXTENSIONS[$file->getMimeType()] ?? 'bin';
        }

        return (int) (microtime(true) * 1000).'-'.random_int(100000000, 999999999).'.'.$ext;
    }

    private function directory(): string
    {
        return (string) config('marketplace.attachments.directory');
    }
}
