<?php

namespace App\Support\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registre des métriques applicatives.
 *
 * Les incréments sont accumulés en mémoire pendant la requête (ou le job),
 * puis écrits en une seule requête SQL à la fin (flush), pour ne pas ralentir
 * le traitement. Une erreur d'écriture n'interrompt jamais l'application.
 */
class MetricsRegistry
{
    /** Bornes des histogrammes de durée, en secondes (mêmes que la v1, plus 5 s). */
    public const BUCKETS = [0.05, 0.1, 0.3, 0.5, 1, 2, 5];

    /** @var array<string, array{name: string, labels: string, value: float}> */
    private array $pending = [];

    public function increment(string $name, array $labels = [], float $by = 1): void
    {
        $encoded = self::encodeLabels($labels);
        $key = $name.'|'.$encoded;
        $this->pending[$key] ??= ['name' => $name, 'labels' => $encoded, 'value' => 0.0];
        $this->pending[$key]['value'] += $by;
    }

    public function observe(string $name, array $labels, float $seconds): void
    {
        foreach (self::BUCKETS as $le) {
            if ($seconds <= $le) {
                $this->increment($name.'_bucket', $labels + ['le' => (string) $le]);
            }
        }
        $this->increment($name.'_bucket', $labels + ['le' => '+Inf']);
        $this->increment($name.'_sum', $labels, $seconds);
        $this->increment($name.'_count', $labels);
    }

    public function observeExternalCall(string $service, string $method, string $status, float $seconds): void
    {
        $this->increment('external_requests_total', ['service' => $service, 'method' => $method, 'status' => $status]);
        $this->observe('external_request_duration_seconds', ['service' => $service], $seconds);
    }

    /** Écrit les incréments en attente. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }
        $rows = array_values($this->pending);
        $this->pending = [];

        try {
            $now = now()->format('Y-m-d H:i:s');
            $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?)'));
            $bindings = [];
            foreach ($rows as $r) {
                array_push($bindings, $r['name'], $r['labels'], $r['value'], $now);
            }

            $sql = "insert into metric_samples (name, labels, value, updated_at) values {$placeholders} ";
            $sql .= DB::getDriverName() === 'mysql'
                ? 'on duplicate key update value = value + values(value), updated_at = values(updated_at)'
                : 'on conflict (name, labels) do update set value = metric_samples.value + excluded.value, updated_at = excluded.updated_at';

            DB::statement($sql, $bindings);
        } catch (Throwable $e) {
            Log::debug('Métriques non enregistrées', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, list<array{labels: array, value: float}>> */
    public function stored(): array
    {
        $out = [];
        foreach (DB::table('metric_samples')->orderBy('name')->orderBy('id')->get() as $row) {
            $out[$row->name][] = ['labels' => json_decode($row->labels, true) ?: [], 'value' => (float) $row->value];
        }

        return $out;
    }

    public static function encodeLabels(array $labels): string
    {
        ksort($labels);

        return json_encode(array_map('strval', $labels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
