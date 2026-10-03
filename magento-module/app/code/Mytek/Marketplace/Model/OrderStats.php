<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

class OrderStats
{
    private const ORDERS = 'orders';
    private const SELLERS = 'marketplace_seller';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnectionByName(SellerRepository::CONNECTION);
    }

    /**
     * @return array{orders:int, revenue:float, active_sellers:int, items:int}
     */
    public function getKpis(): array
    {
        $conn = $this->conn();
        $row = $conn->fetchRow(
            $conn->select()->from(self::ORDERS, [
                'orders'  => new Expression('COUNT(DISTINCT order_id)'),
                'revenue' => new Expression('COALESCE(SUM(price * qty), 0)'),
                'items'   => new Expression('COALESCE(SUM(qty), 0)'),
            ])
        ) ?: [];

        $active = (int)$conn->fetchOne(
            $conn->select()->from(self::SELLERS, new Expression('COUNT(*)'))
                ->where('status = ?', SellerRepository::STATUS_VALIDATED)
        );

        return [
            'orders'         => (int)($row['orders'] ?? 0),
            'revenue'        => (float)($row['revenue'] ?? 0),
            'items'          => (int)($row['items'] ?? 0),
            'active_sellers' => $active,
        ];
    }

    /**
     * @param string $orderBy 'revenue' | 'orders'
     */
    public function getTopSellers(string $orderBy = 'revenue', int $limit = 10): array
    {
        $conn = $this->conn();
        $sort = $orderBy === 'orders' ? 'orders DESC' : 'revenue DESC';
        $select = $conn->select()
            ->from(['o' => self::ORDERS], [
                'vendor_id',
                'orders'  => new Expression('COUNT(DISTINCT o.order_id)'),
                'revenue' => new Expression('COALESCE(SUM(o.price * o.qty), 0)'),
            ])
            ->joinLeft(
                ['s' => self::SELLERS],
                's.seller_id = o.vendor_id',
                ['firstname', 'lastname', 'shop_title', 'email']
            )
            ->group('o.vendor_id')
            ->order(new Expression($sort))
            ->limit($limit);
        return $conn->fetchAll($select);
    }

    /**
     * Lignes détaillées pour l'export CSV.
     */
    public function getExportRows(): array
    {
        $conn = $this->conn();
        $select = $conn->select()
            ->from(['o' => self::ORDERS], ['order_id', 'sku', 'product_name', 'qty', 'price', 'processed_at'])
            ->joinLeft(['s' => self::SELLERS], 's.seller_id = o.vendor_id', ['shop_title', 'email'])
            ->order('o.processed_at DESC');
        return $conn->fetchAll($select);
    }
}
