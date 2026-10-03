<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

class ReclamationRepository
{
    public const TYPE_NOTIFICATION = 0;
    public const TYPE_OPEN = 1;
    public const TYPE_RESOLVED = 2;

    public const SENDER_ADMIN = 0;
    public const SENDER_SELLER = 1;

    private const RECLAMATIONS = 'reclamations';
    private const MESSAGES = 'reclamation_messages';
    private const ATTACHMENTS = 'reclamation_attachments';
    private const SELLERS = 'marketplace_seller';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnectionByName(SellerRepository::CONNECTION);
    }

    /**
     * @param bool $onlyUnseen uniquement les réclamations ouvertes contenant un message non lu par l'admin,
     *                         c'est-à-dire exactement celles que compte countUnseen()
     */
    public function getList(bool $onlyUnseen = false): array
    {
        $conn = $this->conn();
        $select = $conn->select()
            ->from(['r' => self::RECLAMATIONS])
            ->joinLeft(['s' => self::SELLERS], 's.seller_id = r.vendeur_id', ['shop_title', 'firstname', 'lastname', 'email'])
            ->order('r.updated_at DESC');
        if ($onlyUnseen) {
            $select->where('r.type = ?', self::TYPE_OPEN)->where('r.admin_viewed = ?', 0);
        } else {
            $select->where('r.type IN (?)', [self::TYPE_OPEN, self::TYPE_RESOLVED]);
        }
        return $conn->fetchAll($select);
    }

    public function getById(int $id): ?array
    {
        $conn = $this->conn();
        $row = $conn->fetchRow(
            $conn->select()
                ->from(['r' => self::RECLAMATIONS])
                ->joinLeft(['s' => self::SELLERS], 's.seller_id = r.vendeur_id', ['shop_title', 'firstname', 'lastname', 'email'])
                ->where('r.id = ?', $id)
        );
        return $row ?: null;
    }

    /**
     * Messages d'une réclamation, chacun avec sa clé 'attachments' (liste).
     */
    public function getMessages(int $reclamationId): array
    {
        $conn = $this->conn();
        $messages = $conn->fetchAll(
            $conn->select()->from(self::MESSAGES)->where('reclamation_id = ?', $reclamationId)->order('created_at ASC')
        );
        if (!$messages) {
            return [];
        }
        $ids = array_column($messages, 'id');
        $attachments = $conn->fetchAll(
            $conn->select()->from(self::ATTACHMENTS)->where('message_id IN (?)', $ids)
        );
        $byMessage = [];
        foreach ($attachments as $a) {
            $byMessage[$a['message_id']][] = $a;
        }
        foreach ($messages as &$m) {
            $m['attachments'] = $byMessage[$m['id']] ?? [];
        }
        return $messages;
    }

    /**
     * Nom du fichier stocké. Les chemins enregistrés par la v1 sous Windows utilisent « \ ».
     */
    public static function fileName(string $storedPath): string
    {
        return basename(str_replace('\\', '/', $storedPath));
    }

    /** Vrai si le fichier est joint à un message de cette réclamation. */
    public function hasAttachment(int $reclamationId, string $fileName): bool
    {
        $conn = $this->conn();
        $paths = $conn->fetchCol(
            $conn->select()
                ->from(['a' => self::ATTACHMENTS], ['file_path'])
                ->join(['m' => self::MESSAGES], 'm.id = a.message_id', [])
                ->where('m.reclamation_id = ?', $reclamationId)
                ->where('a.file_path LIKE ?', '%' . $fileName)
        );
        foreach ($paths as $path) {
            if (self::fileName((string)$path) === $fileName) {
                return true;
            }
        }
        return false;
    }

    public function reply(int $reclamationId, string $message): void
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $conn->insert(self::MESSAGES, [
                'reclamation_id' => $reclamationId,
                'sender'         => self::SENDER_ADMIN,
                'message'        => $message,
                'created_at'     => new Expression('NOW()'),
            ]);
            $conn->update(self::RECLAMATIONS, [
                'vendeur_viewed' => 0,
                'admin_viewed'   => 1,
                'updated_at'     => new Expression('NOW()'),
            ], ['id = ?' => $reclamationId]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    public function resolve(int $reclamationId): int
    {
        return $this->conn()->update(self::RECLAMATIONS, [
            'type'       => self::TYPE_RESOLVED,
            'updated_at' => new Expression('NOW()'),
        ], ['id = ?' => $reclamationId]);
    }

    public function markSeenByAdmin(int $reclamationId): int
    {
        return $this->conn()->update(self::RECLAMATIONS, ['admin_viewed' => 1], ['id = ?' => $reclamationId]);
    }

    public function countUnseen(): int
    {
        $conn = $this->conn();
        return (int)$conn->fetchOne(
            $conn->select()->from(self::RECLAMATIONS, new Expression('COUNT(*)'))
                ->where('admin_viewed = ?', 0)->where('type = ?', self::TYPE_OPEN)
        );
    }

    public static function isResolved(array $reclamation): bool
    {
        return (int)($reclamation['type'] ?? self::TYPE_OPEN) === self::TYPE_RESOLVED;
    }

    public static function typeLabel(int $type): string
    {
        return (string)match ($type) {
            self::TYPE_RESOLVED => __('Resolved'),
            self::TYPE_OPEN     => __('Open'),
            default             => __('Notification'),
        };
    }
}
