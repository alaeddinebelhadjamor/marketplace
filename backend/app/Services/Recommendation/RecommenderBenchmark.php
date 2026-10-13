<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Banc d'évaluation du moteur sur un jeu de commandes externe (ex. Online Retail II
 * converti au format `orders`), pour la tâche « évaluation sur jeu public ».
 *
 * Même protocole que RecommenderEvaluator (séparation au niveau du panier, paires
 * (seed, target) sur tous les paniers de test, Hit Rate@10), avec en plus :
 *  - NDCG@10, MRR (rang exact dans le classement complet) et couverture du catalogue ;
 *  - un jeu de validation distinct pour choisir K (le test n'est jamais utilisé pour choisir) ;
 *  - l'ablation ALS seul / contenu seul / hybride, et la base « produits les plus vendus ».
 *
 * Choix de rigueur :
 *  - la popularité exclut la graine de sa liste (comme ALS) : comparaison à armes égales ;
 *  - un seul classement complet est calculé par graine (pas par paire) : résultats identiques,
 *    coût divisé par le nombre de paires ;
 *  - les similarités de contenu viennent d'une matrice précalculée (content_similarity_matrix.py).
 *
 * Ne lit ni n'écrit la base applicative : la connexion est celle passée au constructeur.
 */
class RecommenderBenchmark
{
    public const TOP_K = 10;

    public const TEST_RATIO = 0.2;

    /** Part de validation, prise dans les 80 % restants (donc 10 % du total). */
    public const VAL_RATIO_OF_DEV = 0.125;

    public const K_CANDIDATES = [4, 8, 12, 16];

    private const FIXED = ['iterations' => 15, 'lambda' => 0.1, 'alpha' => 40.0];

    private ?string $simBin = null;

    /** @var array<string,int> sku -> index dans la matrice de contenu */
    private array $simIndex = [];

    private int $simN = 0;

    /** @param  (callable(string):void)|null  $log */
    public function __construct(
        private readonly string $connection,
        private readonly ?string $simBinPath,
        private readonly ?string $simSkusPath,
        private readonly float $wCollaborative,
        private readonly float $wContent,
        private $log = null,
        private readonly bool $binaryQty = false,
    ) {
        if ($simBinPath !== null && $simSkusPath !== null) {
            $skus = json_decode((string) file_get_contents($simSkusPath), true);
            $this->simN = count($skus);
            $this->simIndex = array_flip($skus);
            $bin = file_get_contents($simBinPath);
            if ($bin === false || strlen($bin) !== $this->simN * $this->simN * 4) {
                throw new RuntimeException('Matrice de contenu absente ou de taille inattendue.');
            }
            $this->simBin = $bin;
        }
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)(date('H:i:s').' '.$m);
        }
    }

    /** @return array<string, array<string,int>> order_id => [sku => qty] */
    public function loadBaskets(): array
    {
        $baskets = [];
        foreach (DB::connection($this->connection)->table('orders')->select(['order_id', 'sku', 'qty'])->cursor() as $r) {
            $baskets[$r->order_id][$r->sku] = (int) $r->qty;
        }
        if ($baskets === []) {
            throw new RuntimeException('Aucune commande dans la base de test.');
        }

        return $baskets;
    }

    /**
     * Séparation entraînement / validation / test au niveau du panier.
     * Même algorithme de mélange que RecommenderEvaluator (Fisher-Yates, mt_rand).
     * Les paniers à un seul SKU ne peuvent pas être évalués : ils restent dans l'entraînement.
     *
     * @return array{fit: array, dev: array, val: array, test: array, stats: array}
     */
    public function split(array $baskets, int $seed): array
    {
        $evaluable = array_filter($baskets, fn ($items) => count($items) >= 2);
        $single = array_filter($baskets, fn ($items) => count($items) < 2);

        $ids = array_keys($evaluable);
        mt_srand($seed);
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }

        $nTest = count($ids) - (int) floor(count($ids) * (1 - self::TEST_RATIO));
        $testIds = array_slice($ids, count($ids) - $nTest);
        $devIds = array_slice($ids, 0, count($ids) - $nTest);
        $nVal = (int) round(count($devIds) * self::VAL_RATIO_OF_DEV);
        $valIds = array_slice($devIds, count($devIds) - $nVal);
        $fitIds = array_slice($devIds, 0, count($devIds) - $nVal);

        $pick = fn (array $keys) => array_intersect_key($evaluable, array_flip($keys));

        return [
            'fit' => $pick($fitIds) + $single,
            'dev' => $pick($devIds) + $single,
            'val' => $pick($valIds),
            'test' => $pick($testIds),
            'stats' => [
                'baskets_total' => count($baskets),
                'baskets_evaluable' => count($evaluable),
                'baskets_single_sku' => count($single),
                'baskets_fit' => count($fitIds),
                'baskets_val' => count($valIds),
                'baskets_test' => count($testIds),
            ],
        ];
    }

    /** @return list<array{basket_id: string, sku: string, qty: int}> */
    private function toRows(array $baskets): array
    {
        $rows = [];
        foreach ($baskets as $id => $items) {
            foreach ($items as $sku => $qty) {
                $rows[] = ['basket_id' => (string) $id, 'sku' => (string) $sku, 'qty' => $this->binaryQty ? 1 : $qty];
            }
        }

        return $rows;
    }

    /**
     * Pipeline complet pour une graine : choix de K sur validation, réentraînement
     * sur entraînement+validation, mesure finale sur le test.
     */
    public function runSeed(array $baskets, int $seed, array $kCandidates = self::K_CANDIDATES): array
    {
        $t0 = microtime(true);
        $s = $this->split($baskets, $seed);
        $fitRows = $this->toRows($s['fit']);
        $devRows = $this->toRows($s['dev']);
        $valSets = array_map('array_keys', $s['val']);
        $testSets = array_map('array_keys', $s['test']);

        $this->say("graine $seed : split OK ({$s['stats']['baskets_fit']} fit / {$s['stats']['baskets_val']} val / {$s['stats']['baskets_test']} test)");

        // 1) Choix de K sur la validation (critère : NDCG@10), modèle appris sur « fit » seulement.
        $valByK = [];
        foreach ($kCandidates as $k) {
            $tk = microtime(true);
            $trained = Als::train($fitRows, $k, self::FIXED['iterations'], self::FIXED['lambda'], self::FIXED['alpha'], $seed);
            $m = $this->evaluateSets($trained, $fitRows, $valSets);
            $valByK[$k] = ['metrics' => $m, 'train_seconds' => round(microtime(true) - $tk, 1)];
            $this->say(sprintf(
                '  val K=%d : ALS ndcg=%.4f | hybride ndcg=%.4f (%.0fs)',
                $k, $m['als']['ndcg10'], $m['hybrid']['ndcg10'], microtime(true) - $tk
            ));
        }

        $bestK = fn (string $method) => (function () use ($valByK, $method) {
            $best = null;
            foreach ($valByK as $k => $v) {
                if ($best === null || $v['metrics'][$method]['ndcg10'] > $valByK[$best]['metrics'][$method]['ndcg10']) {
                    $best = $k;
                }
            }

            return $best;
        })();
        $kAls = $bestK('als');
        $kHybrid = $bestK('hybrid');

        // 2) Mesure finale sur le test, modèle réentraîné sur fit + val (= 80 %).
        $finalByK = [];
        foreach (array_unique([$kAls, $kHybrid]) as $k) {
            $trained = Als::train($devRows, $k, self::FIXED['iterations'], self::FIXED['lambda'], self::FIXED['alpha'], $seed);
            $finalByK[$k] = $this->evaluateSets($trained, $devRows, $testSets);
            $this->say("  test K=$k évalué");
        }

        $final = [
            'als' => $finalByK[$kAls]['als'],
            'hybrid' => $finalByK[$kHybrid]['hybrid'],
            'content' => $finalByK[$kAls]['content'],
            'cooccurrence' => $finalByK[$kAls]['cooccurrence'],
            'popularity' => $finalByK[$kAls]['popularity'],
        ];

        return [
            'seed' => $seed,
            'split' => $s['stats'],
            'k_als' => $kAls,
            'k_hybrid' => $kHybrid,
            'validation_by_k' => $valByK,
            'test' => $final,
            'test_pairs_evaluable' => $finalByK[$kAls]['als']['pairs'],
            'test_pairs_total' => $finalByK[$kAls]['_pairs_total'],
            'seconds' => round(microtime(true) - $t0, 1),
        ];
    }

    /**
     * Évalue ALS, contenu, hybride et popularité sur une liste de paniers (listes de SKU).
     *
     * @param  array{skus: list<string>, item_factors: list<list<float>>}  $trained
     * @param  list<array{basket_id: string, sku: string, qty: int}>  $trainRows
     * @param  list<list<string>>  $evalSets
     */
    public function evaluateSets(array $trained, array $trainRows, array $evalSets): array
    {
        $skus = $trained['skus'];
        $n = count($skus);
        $idx = array_flip($skus);
        $dim = count($trained['item_factors'][0]);

        // Facteurs normalisés : le cosinus devient un produit scalaire.
        $F = [];
        foreach ($trained['item_factors'] as $i => $v) {
            $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1e-8;
            $F[$i] = array_map(fn ($x) => $x / $norm, $v);
        }

        // Correspondance candidat -> colonne de la matrice de contenu (-1 si SKU sans plongement).
        $emb = [];
        foreach ($skus as $i => $sku) {
            $emb[$i] = $this->simIndex[$sku] ?? -1;
        }

        // Popularité (comptage de lignes, comme RecommenderEvaluator), ordre déterministe.
        $count = array_fill(0, $n, 0);
        foreach ($trainRows as $r) {
            if (isset($idx[$r['sku']])) {
                $count[$idx[$r['sku']]]++;
            }
        }
        $order = range(0, $n - 1);
        usort($order, fn ($a, $b) => $count[$b] <=> $count[$a] ?: $a <=> $b);
        $popRank = array_flip($order); // indice candidat -> position 0-based

        // Paniers de test restreints au vocabulaire entraîné ; index inversé graine -> paniers.
        $bySeed = [];
        $pairsTotal = 0;
        $pairsEval = 0;
        foreach ($evalSets as $b => $set) {
            $m = count($set);
            $pairsTotal += $m * ($m - 1);
            $ids = [];
            foreach ($set as $sku) {
                if (isset($idx[$sku])) {
                    $ids[] = $idx[$sku];
                }
            }
            $mm = count($ids);
            if ($mm < 2) {
                continue;
            }
            $pairsEval += $mm * ($mm - 1);
            foreach ($ids as $s) {
                $bySeed[$s][] = $ids;
            }
        }

        // Co-occurrence (cosinus normalisé par la fréquence des articles) : base de référence
        // « item-kNN » sans apprentissage, qui valide que le protocole contient du signal de co-achat.
        $inv = [];
        $bk = [];
        $groups = [];
        foreach ($trainRows as $r) {
            if (isset($idx[$r['sku']])) {
                $groups[$r['basket_id']][$idx[$r['sku']]] = true;
            }
        }
        foreach ($groups as $members) {
            $ids = array_keys($members);
            if (count($ids) < 2) {
                continue;
            }
            $b = count($bk);
            $bk[$b] = $ids;
            foreach ($ids as $i) {
                $inv[$i][] = $b;
            }
        }
        unset($groups);

        $methods = ['als', 'content', 'hybrid', 'cooccurrence', 'popularity'];
        $acc = [];
        foreach ($methods as $mth) {
            $acc[$mth] = ['hits' => 0, 'ndcg' => 0.0, 'rr' => 0.0, 'pairs' => 0, 'covered' => []];
        }

        $simBytes = $this->simN * 4;
        foreach ($bySeed as $s => $baskets) {
            $fs = $F[$s];
            $als = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i === $s) {
                    continue;
                }
                $d = 0.0;
                $fi = $F[$i];
                for ($j = 0; $j < $dim; $j++) {
                    $d += $fs[$j] * $fi[$j];
                }
                $als[$i] = $d;
            }

            $content = [];
            if ($this->simBin !== null && $emb[$s] >= 0) {
                $row = unpack('g*', substr($this->simBin, $emb[$s] * $simBytes, $simBytes));
                for ($i = 0; $i < $n; $i++) {
                    if ($i !== $s) {
                        $content[$i] = $emb[$i] >= 0 ? $row[$emb[$i] + 1] : 0.0;
                    }
                }
            } else {
                $content = array_fill_keys(array_keys($als), 0.0);
            }

            $hybrid = [];
            foreach ($als as $i => $v) {
                $hybrid[$i] = $this->wCollaborative * $v + $this->wContent * $content[$i];
            }

            $cooc = array_fill_keys(array_keys($als), 0.0);
            $dfS = count($inv[$s] ?? []);
            if ($dfS > 0) {
                $cnt = [];
                foreach ($inv[$s] as $b) {
                    foreach ($bk[$b] as $i) {
                        $cnt[$i] = ($cnt[$i] ?? 0) + 1;
                    }
                }
                foreach ($cnt as $i => $c) {
                    if ($i !== $s) {
                        $cooc[$i] = $c / sqrt($dfS * count($inv[$i]));
                    }
                }
            }

            // Classement de la popularité sans la graine : rang(t) = pos(t) - [pos(s) < pos(t)].
            $ps = $popRank[$s];

            foreach (['als' => $als, 'content' => $content, 'hybrid' => $hybrid, 'cooccurrence' => $cooc] as $mth => $scores) {
                arsort($scores); // tri stable (PHP >= 8.0) : égalités départagées par indice
                $rank = [];
                $pos = 0;
                foreach ($scores as $i => $_) {
                    $rank[$i] = ++$pos;
                    if ($pos <= self::TOP_K) {
                        $acc[$mth]['covered'][$i] = true;
                    }
                }
                $this->accumulate($acc[$mth], $baskets, $s, fn ($t) => $rank[$t]);
            }

            $pr = fn ($t) => $popRank[$t] + 1 - ($ps < $popRank[$t] ? 1 : 0);
            $this->accumulate($acc['popularity'], $baskets, $s, $pr);
            // Couverture de la popularité : top-10 hors graine.
            foreach (array_slice($order, 0, self::TOP_K + 1) as $i) {
                if ($i !== $s) {
                    $acc['popularity']['covered'][$i] = true;
                }
            }
        }

        $out = ['_pairs_total' => $pairsTotal];
        foreach ($methods as $mth) {
            $a = $acc[$mth];
            $p = max($a['pairs'], 1);
            $out[$mth] = [
                'pairs' => $a['pairs'],
                'hit10' => $a['hits'] / $p,
                'ndcg10' => $a['ndcg'] / $p,
                'mrr' => $a['rr'] / $p,
                'coverage10' => count($a['covered']) / $n,
            ];
        }

        return $out;
    }

    /** @param  callable(int):int  $rankOf */
    private function accumulate(array &$a, array $baskets, int $s, callable $rankOf): void
    {
        foreach ($baskets as $ids) {
            foreach ($ids as $t) {
                if ($t === $s) {
                    continue;
                }
                $r = $rankOf($t);
                $a['pairs']++;
                $a['rr'] += 1.0 / $r;
                if ($r <= self::TOP_K) {
                    $a['hits']++;
                    $a['ndcg'] += 1.0 / log($r + 1, 2);
                }
            }
        }
    }
}
