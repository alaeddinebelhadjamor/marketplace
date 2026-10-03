<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Setup\Patch\Data;

use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Accorde à l'intégrateur la ressource Mytek_Marketplace::notifications (cas 2.17 : « l'intégrateur
 * peut également adresser une notification à un vendeur »). Décision X4 de l'audit : jusqu'ici
 * seule l'API l'exposait (clé x-admin-key), que l'intégrateur n'a pas.
 *
 * Patch distinct de CreateMarketplaceRoles, déjà appliqué sur les Magento existants : on ne
 * modifie jamais un data patch déjà exécuté, on en ajoute un nouveau qui en dépend.
 */
class GrantNotificationsResourceToIntegrator implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly RulesFactory $rulesFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $this->setResources(array_merge(
            CreateMarketplaceRoles::RESOURCES_INTEGRATOR,
            ['Mytek_Marketplace::notifications']
        ));
        $this->moduleDataSetup->endSetup();
        return $this;
    }

    /** Revient à l'ensemble de ressources posé par CreateMarketplaceRoles, sans la notification. */
    public function revert(): void
    {
        $this->moduleDataSetup->startSetup();
        $this->setResources(CreateMarketplaceRoles::RESOURCES_INTEGRATOR);
        $this->moduleDataSetup->endSetup();
    }

    private function setResources(array $resources): void
    {
        $collection = $this->roleCollectionFactory->create();
        $collection->addFieldToFilter('role_name', CreateMarketplaceRoles::ROLE_INTEGRATOR)
            ->addFieldToFilter('role_type', RoleGroup::ROLE_TYPE);
        $role = $collection->getFirstItem();
        if (!$role->getId()) {
            // Dépendance non respectée (rôle pas encore créé) : rien à faire, setup:upgrade
            // garantit normalement l'ordre via getDependencies().
            return;
        }
        $this->rulesFactory->create()->setRoleId($role->getId())->setResources($resources)->saveRel();
    }

    public static function getDependencies(): array
    {
        return [CreateMarketplaceRoles::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
