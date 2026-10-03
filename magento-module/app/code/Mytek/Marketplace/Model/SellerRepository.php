<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

class SellerRepository
{
    public const STATUS_PENDING = 0;
    public const STATUS_VALIDATED = 1;
    public const STATUS_REFUSED = 2;

    public const CONNECTION = 'marketplace';
    private const TABLE = 'marketplace_seller';

    private const EDITABLE = [
        'firstname', 'lastname', 'email', 'shop_title', 'company', 'contact_number',
        'description', 'address', 'zipcode', 'governorate', 'has_patent', 'tax_id',
    ];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnectionByName(self::CONNECTION);
    }

    public function getList(?string $search = null, ?int $status = null): array
    {
        $conn = $this->conn();
        $select = $conn->select()->from(self::TABLE)->order('created_at DESC');
        if ($status !== null) {
            $select->where('status = ?', $status);
        }
        if ($search !== null && trim($search) !== '') {
            // Valeur liée (quoteInto remplace chaque « ? »), jokers LIKE de la saisie neutralisés
            $select->where(
                '(firstname LIKE ? OR lastname LIKE ? OR email LIKE ? OR shop_title LIKE ?)',
                '%' . self::escapeLike(trim($search)) . '%'
            );
        }
        return $conn->fetchAll($select);
    }

    /** Neutralise les jokers « % » et « _ » d'une saisie utilisée dans un LIKE. */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    public function getById(int $id): ?array
    {
        $conn = $this->conn();
        $row = $conn->fetchRow($conn->select()->from(self::TABLE)->where('seller_id = ?', $id));
        return $row ?: null;
    }

    public function setStatus(int $id, int $status): int
    {
        return $this->conn()->update(
            self::TABLE,
            ['status' => $status, 'updated_at' => new Expression('NOW()')],
            ['seller_id = ?' => $id]
        );
    }

    public function update(int $id, array $data): int
    {
        $clean = array_intersect_key($data, array_flip(self::EDITABLE));
        $clean['has_patent'] = !empty($clean['has_patent']) ? 1 : 0;
        $clean['updated_at'] = new Expression('NOW()');
        return $this->conn()->update(self::TABLE, $clean, ['seller_id = ?' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->conn()->delete(self::TABLE, ['seller_id = ?' => $id]);
    }

    public function countByStatus(): array
    {
        $conn = $this->conn();
        $rows = $conn->fetchPairs(
            $conn->select()
                ->from(self::TABLE, ['status' => 'status', 'nb' => new Expression('COUNT(*)')])
                ->group('status')
        );
        return [
            self::STATUS_PENDING   => (int)($rows[self::STATUS_PENDING] ?? 0),
            self::STATUS_VALIDATED => (int)($rows[self::STATUS_VALIDATED] ?? 0),
            self::STATUS_REFUSED   => (int)($rows[self::STATUS_REFUSED] ?? 0),
        ];
    }

    public static function statusLabel(int $status): string
    {
        return (string)match ($status) {
            self::STATUS_VALIDATED => __('Approved'),
            self::STATUS_REFUSED   => __('Refused'),
            default                => __('Pending'),
        };
    }
}
