<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Attribut produit `seller_id` : rattache chaque produit du catalogue à son vendeur
 * (marketplace_seller.seller_id). Il est renseigné automatiquement par la passerelle
 * Node.js lors de la soumission d'un produit (POST /api/magento/product/add) et sert
 * ensuite au filtrage des produits par vendeur et au contrôle de propriété.
 */
class AddSellerIdAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'seller_id';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
                'type'                    => 'int',
                'label'                   => 'Vendeur marketplace (seller_id)',
                'input'                   => 'text',
                'required'                => false,
                'user_defined'            => true,
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => true,
                'is_used_in_grid'         => true,
                'is_visible_in_grid'      => true,
                'is_filterable_in_grid'   => true,
                'group'                   => 'General',
                'sort_order'              => 200,
                'note'                    => 'Identifiant du vendeur (table marketplace_seller). Renseigné automatiquement par la passerelle Node.js.',
            ]);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
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
