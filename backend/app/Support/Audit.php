<?php

namespace App\Support;

use App\Models\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Journal d'audit des actions sensibles (table activity_log).
 *
 * Un échec d'écriture du journal ne doit jamais faire échouer l'action
 * métier : il est seulement signalé dans les logs applicatifs.
 */
class Audit
{
    public static function log(string $event, string $description, ?Seller $causer = null, ?Model $subject = null, array $properties = []): void
    {
        try {
            $request = request();
            $logger = activity('security')
                ->event($event)
                ->withProperties(array_merge($properties, [
                    'ip' => $request?->ip(),
                    'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
                ]));

            $causer ??= $request?->user() instanceof Seller ? $request->user() : null;
            if ($causer) {
                $logger->causedBy($causer);
            }
            if ($subject) {
                $logger->performedOn($subject);
            }

            $logger->log($description);
        } catch (Throwable $e) {
            Log::warning('Échec d\'écriture du journal d\'audit', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
