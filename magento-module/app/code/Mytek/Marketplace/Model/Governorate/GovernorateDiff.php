<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Governorate;

/**
 * Calcul pur (sans dépendance Magento) du nécessaire à synchroniser : quels produits doivent
 * recevoir quelle valeur de l'attribut seller_governorate, ou doivent être vidés. Séparé de
 * GovernorateSyncService pour rester testable unitairement sans base de données ni collection
 * Magento.
 */
class GovernorateDiff
{
    /**
     * @param array<int, string> $sellerGovernorates seller_id => libellé du gouvernorat (base marketplace)
     * @param array<int, string> $optionIdByLabel     libellé => option_id de l'attribut (Magento)
     * @param array<int, array{seller_id:int, current_option_id:?int}> $products product_id => état actuel
     * @return array{toSet: array<int, int[]>, toClear: int[], checked: int, updated: int}
     *         toSet : option_id => liste d'ids produits à mettre à cette valeur
     */
    public static function compute(array $sellerGovernorates, array $optionIdByLabel, array $products): array
    {
        $toSet = [];
        $toClear = [];
        $checked = 0;
        $updated = 0;

        foreach ($products as $productId => $state) {
            $checked++;
            $sellerId = (int)($state['seller_id'] ?? 0);
            $currentOptionId = $state['current_option_id'] ?? null;

            $governorateLabel = $sellerGovernorates[$sellerId] ?? null;
            $expectedOptionId = ($governorateLabel !== null && isset($optionIdByLabel[$governorateLabel]))
                ? $optionIdByLabel[$governorateLabel]
                : null;

            if ($expectedOptionId === $currentOptionId) {
                continue; // déjà à jour, y compris quand les deux valent null
            }

            $updated++;
            if ($expectedOptionId === null) {
                $toClear[] = (int)$productId;
            } else {
                $toSet[$expectedOptionId][] = (int)$productId;
            }
        }

        return ['toSet' => $toSet, 'toClear' => $toClear, 'checked' => $checked, 'updated' => $updated];
    }
}
