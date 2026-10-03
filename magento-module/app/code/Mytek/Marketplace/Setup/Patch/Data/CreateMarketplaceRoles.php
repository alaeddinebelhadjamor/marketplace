<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Setup\Patch\Data;

use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\Role as RoleModel;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Rôles « Intégrateur Marketplace » et « Administrateur Marketplace » (ch. 2 § 2.1 : « L'administrateur
 * hérite des capacités de l'intégrateur et les étend »).
 *
 * L'intégrateur reçoit les ressources de modération opérationnelle décrites au ch. 3 § 3.4.2
 * (consultation et modification des vendeurs, réclamations, catalogue), jamais la validation,
 * le refus ou la suppression d'un vendeur, ni le tableau de bord Marketplace qui porte des
 * données financières (ch. 2 § 2.1 : « l'administrateur ... supervise les aspects financiers »).
 * L'administrateur reçoit l'accès complet (Magento_Backend::all), comme le rôle « Administrators »
 * natif de Magento.
 *
 * Idempotent : si un rôle du même nom existe déjà (cas de ce Magento local, configuré à la main
 * avant l'écriture de ce patch), ses ressources sont simplement remises à cet état plutôt que
 * de créer un doublon.
 */
class CreateMarketplaceRoles implements DataPatchInterface, PatchRevertableInterface
{
    public const ROLE_INTEGRATOR = 'Intégrateur Marketplace';
    public const ROLE_ADMIN = 'Administrateur Marketplace';

    /** Ressources de l'intégrateur : visibilité publique pour être étendues par un patch ultérieur
     *  (voir GrantNotificationsResourceToIntegrator) sans jamais modifier ce patch déjà appliqué. */
    public const RESOURCES_INTEGRATOR = [
        'Magento_Backend::admin',
        'Magento_Catalog::catalog',
        'Magento_Catalog::catalog_inventory',
        'Magento_Catalog::categories',
        'Magento_Catalog::products',
        'Mytek_Marketplace::marketplace',
        'Mytek_Marketplace::sellers',
        'Mytek_Marketplace::sellers_edit',
        'Mytek_Marketplace::reclamations',
    ];

    private const RESOURCES_ADMIN = ['Magento_Backend::all'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly RoleFactory $roleFactory,
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly RulesFactory $rulesFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $this->ensureRole(self::ROLE_INTEGRATOR, self::RESOURCES_INTEGRATOR);
        $this->ensureRole(self::ROLE_ADMIN, self::RESOURCES_ADMIN);
        $this->moduleDataSetup->endSetup();
        return $this;
    }

    /**
     * Révoque les ressources attribuées par ce patch, sans supprimer les rôles : des
     * administrateurs peuvent déjà leur être rattachés, et un rôle sans aucun utilisateur ne
     * gêne pas Magento. Supprimer le rôle romprait leur rattachement.
     */
    public function revert(): void
    {
        $this->moduleDataSetup->startSetup();
        foreach ([self::ROLE_INTEGRATOR, self::ROLE_ADMIN] as $name) {
            $role = $this->findRole($name);
            if ($role) {
                $this->rulesFactory->create()->setRoleId($role->getId())->setResources([])->saveRel();
            }
        }
        $this->moduleDataSetup->endSetup();
    }

    private function findRole(string $name): ?RoleModel
    {
        $collection = $this->roleCollectionFactory->create();
        $collection->addFieldToFilter('role_name', $name)->addFieldToFilter('role_type', RoleGroup::ROLE_TYPE);
        /** @var RoleModel $role */
        $role = $collection->getFirstItem();
        return $role->getId() ? $role : null;
    }

    private function ensureRole(string $name, array $resources): void
    {
        $role = $this->findRole($name);
        if (!$role) {
            $role = $this->roleFactory->create();
            $role->setName($name)
                ->setPid(0)
                ->setRoleType(RoleGroup::ROLE_TYPE)
                ->setUserType(UserContextInterface::USER_TYPE_ADMIN)
                ->save();
        }
        $this->rulesFactory->create()->setRoleId($role->getId())->setResources($resources)->saveRel();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
