<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Mytek\Marketplace\Model\Governorate\GovernorateList;
use Mytek\Marketplace\Model\Governorate\GovernorateSyncService;

/**
 * Attribut produit `seller_governorate` : facette de navigation par gouvernorat du vendeur
 * (amélioration B, écarts 1 et 3 de l'audit ; ch. 2 § 2.2.3, ch. 3 § 3.5.2). Ce n'est pas un
 * attribut natif du storefront : le module le crée et le tient à jour (voir
 * GovernorateSyncService, appelé ici une première fois pour les produits déjà en catalogue, et
 * périodiquement par la tâche planifiée mytek_marketplace_sync_seller_governorate).
 *
 * Type select, options = les 24 gouvernorats (Model\Governorate\GovernorateList, même liste que
 * le formulaire vendeur de l'admin) : les attributs de type texte libre ne sont pas filtrables
 * en navigation à facettes dans Magento, seuls select/multiselect et prix le sont.
 */
class AddSellerGovernorateAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'seller_governorate';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly GovernorateSyncService $syncService
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
                'type'                      => 'int',
                'label'                     => 'Seller governorate',
                'input'                     => 'select',
                'source'                    => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option'                    => ['values' => GovernorateList::ALL],
                'required'                  => false,
                'user_defined'              => true,
                'global'                    => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible'                   => true,
                'searchable'                => false,
                'filterable'                => true,
                'filterable_in_search'      => true,
                'visible_on_front'          => false,
                'used_in_product_listing'   => true,
                'is_used_in_grid'           => true,
                'is_visible_in_grid'        => true,
                'is_filterable_in_grid'     => true,
                'group'                     => 'General',
                'sort_order'                => 201,
                'note'                      => 'Governorate of the owning seller (marketplace_seller.governorate). '
                    . 'Kept in sync by Mytek_Marketplace (data patch + scheduled task), never edited by hand.',
            ]);
        }

        $this->moduleDataSetup->endSetup();

        // Remplit l'attribut pour les produits déjà en catalogue, sans attendre le premier
        // passage de la tâche planifiée.
        $this->syncService->sync();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddSellerIdAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
