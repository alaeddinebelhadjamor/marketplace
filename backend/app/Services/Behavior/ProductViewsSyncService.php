<?php

namespace App\Services\Behavior;

use App\Models\UserProductBehavior;
use App\Support\Metrics\MetricsRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline de suivi comportemental : lecture incrémentale du journal Apache
 * depuis l'offset sauvegardé, classement, résolution des slugs, insertion dans
 * user_product_behavior. Relancer le traitement ne crée pas de doublon.
 */
class ProductViewsSyncService
{
    private const BATCH = 500;

    public function __construct(
        private readonly AccessLogParser $parser,
        private readonly SlugResolver $resolver,
        private readonly MetricsRegistry $metrics,
    ) {}

    /** @return array{inserted: int, skipped: int, offset: int} */
    public function sync(?string $logFile = null, ?string $offsetFile = null): array
    {
        $logFile ??= (string) config('marketplace.behavior.log_file');
        $offsetFile = $this->absolute($offsetFile ?? (string) config('marketplace.behavior.offset_file'));

        if ($logFile === '' || ! is_file($logFile)) {
            Log::warning('Journal Apache introuvable pour le suivi comportemental', ['file' => $logFile]);

            return ['inserted' => 0, 'skipped' => 0, 'offset' => 0];
        }

        $size = filesize($logFile) ?: 0;
        $offset = $this->loadOffset($offsetFile);
        if ($offset > $size) {
            $offset = 0; // journal remplacé (rotation) : on repart du début
        }
        if ($offset === $size) {
            return ['inserted' => 0, 'skipped' => 0, 'offset' => $offset];
        }

        $handle = fopen($logFile, 'rb');
        fseek($handle, $offset);

        $rows = [];
        $inserted = 0;
        $skipped = 0;
        $position = $offset;

        while (($line = fgets($handle)) !== false) {
            // Ligne incomplète en fin de fichier : elle sera lue au prochain passage.
            if (! str_ends_with($line, "\n")) {
                break;
            }
            $position += strlen($line);

            $event = $this->toEvent($line);
            if ($event === null) {
                $skipped++;

                continue;
            }
            $rows[] = $event;

            if (count($rows) >= self::BATCH) {
                $inserted += $this->insert($rows);
                $rows = [];
            }
        }
        fclose($handle);

        $inserted += $this->insert($rows);
        $this->saveOffset($offsetFile, $position);

        $this->metrics->increment('marketplace_behavior_events_total', [], $inserted);
        $this->metrics->increment('marketplace_sync_runs_total', ['task' => 'product_views', 'result' => 'success']);
        $this->metrics->flush();

        return ['inserted' => $inserted, 'skipped' => $skipped, 'offset' => $position];
    }

    private function toEvent(string $line): ?array
    {
        $parsed = $this->parser->parse($line);
        if ($parsed === null) {
            return null;
        }

        if ($parsed['type'] === 'search') {
            $type = ['event_type' => 'search', 'product_sku' => null, 'category_id' => null, 'search_query' => $parsed['search_query']];
        } else {
            $resolved = $this->resolver->resolve($parsed['slug']);
            if ($resolved === null) {
                return null;
            }
            $type = $resolved + ['search_query' => null];
        }

        return $type + [
            'user_id' => null,
            'anonymous_id' => $parsed['anonymous_id'],
            'session_id' => $parsed['session_id'],
            'source' => $parsed['source'],
            'device_type' => $parsed['device_type'],
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function insert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        UserProductBehavior::query()->insert($rows);

        return count($rows);
    }

    private function loadOffset(string $file): int
    {
        if (! is_file($file)) {
            return 0;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return max(0, (int) ($data['offset'] ?? 0));
    }

    private function saveOffset(string $file, int $offset): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode(['offset' => $offset, 'saved_at' => now()->toIso8601String()]), LOCK_EX);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
