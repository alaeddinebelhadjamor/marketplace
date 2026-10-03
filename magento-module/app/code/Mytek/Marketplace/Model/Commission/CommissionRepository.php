<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Commission;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Mytek\Marketplace\Model\SellerRepository;

/**
 * Lecture directe des tables de commissions de la v2 (`commission_rates`, `payout_statements`),
 * qui vivent dans la même base marketplace que le reste du module : pas besoin de passer par
 * l'API pour les consulter. Seules les actions qui changent un état (générer un relevé, changer
 * un taux, rendre un PDF) passent par l'API v2, qui porte cette logique métier
 * (`Model\Commission\CommissionAdminProxy`) — on ne la duplique pas ici.
 */
class CommissionRepository
{
    /** Taux par défaut si aucune ligne commission_rates pour ce vendeur (5 % dans la v1 et la v2). */
    public const DEFAULT_RATE = 0.05;

    private const RATES_TABLE = 'commission_rates';
    private const STATEMENTS_TABLE = 'payout_statements';
    private const SELLERS_TABLE = 'marketplace_seller';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnectionByName(SellerRepository::CONNECTION);
    }

    /**
     * @param int[] $sellerIds
     * @return array<int, float> taux effectif par vendeur (personnalisé ou par défaut)
     */
    public function getRates(array $sellerIds): array
    {
        $out = array_fill_keys($sellerIds, self::DEFAULT_RATE);
        if (!$sellerIds) {
            return $out;
        }
        $conn = $this->conn();
        $rows = $conn->fetchPairs(
            $conn->select()->from(self::RATES_TABLE, ['seller_id', 'rate'])->where('seller_id IN (?)', $sellerIds)
        );
        foreach ($rows as $sellerId => $rate) {
            $out[(int)$sellerId] = (float)$rate;
        }
        return $out;
    }

    /**
     * @param ?string $period AAAA-MM ; toutes les périodes si omis
     */
    public function getStatements(?string $period = null): array
    {
        $conn = $this->conn();
        $select = $conn->select()
            ->from(['p' => self::STATEMENTS_TABLE])
            ->joinLeft(['s' => self::SELLERS_TABLE], 's.seller_id = p.seller_id', ['shop_title'])
            ->order(['p.period DESC', 'p.seller_id ASC']);
        if ($period !== null && $period !== '') {
            $select->where('p.period = ?', $period);
        }
        return $conn->fetchAll($select);
    }
}
