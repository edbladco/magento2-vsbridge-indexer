<?php
/**
 * @package   Divante\VsbridgeIndexerCatalog
 * @author    Agata Firlejczyk <afirlejczyk@divante.pl>
 * @copyright 2019 Divante Sp. z o.o.
 * @license   See LICENSE_DIVANTE.txt for license details.
 */

declare(strict_types=1);

namespace Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product;

use Divante\VsbridgeIndexerCatalog\Model\Inventory\Fields as InventoryFields;
use Magento\Framework\App\ResourceConnection;
use Magento\CatalogInventory\Api\StockConfigurationInterface;

/**
 * Class Inventory
 */
class Inventory
{
    /**
     * @var StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var InventoryFields
     */
    private $inventoryFields;

    /**
     * Inventory constructor.
     *
     * @param StockConfigurationInterface $stockConfiguration
     * @param InventoryFields $fields
     * @param ResourceConnection $resourceModel
     */
    public function __construct(
        StockConfigurationInterface $stockConfiguration,
        InventoryFields $fields,
        ResourceConnection $resourceModel
    ) {
        $this->inventoryFields = $fields;
        $this->resource = $resourceModel;
        $this->stockConfiguration = $stockConfiguration;
    }

    /**
     * @param array $productIds
     *
     * @return array
     */
    public function loadInventory(array $productIds): array
    {
        return $this->getInventoryData($productIds, $this->inventoryFields->getRequiredColumns());
    }

    /**
     * @param array $productIds
     *
     * @return array
     */
    public function loadChildrenInventory(array $productIds): array
    {
        return $this->getInventoryData($productIds, $this->inventoryFields->getChildRequiredColumns());
    }

    /**
     * @param array $productIds
     * @param array $fields
     *
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function getInventoryData(array $productIds, array $fields): array
    {
        $websiteId = $this->getWebsiteId();
        $connection = $this->resource->getConnection();

        $fieldsToOverride = [
            'qty',
            'is_in_stock'
        ];

        foreach ($fieldsToOverride as $field) {
            if (($key = array_search($field, $fields)) !== false) {
                unset($fields[$key]);
            }
        }

        $expressionsToSelect = [
            new \Zend_Db_Expr('main_table.qty + SUM(COALESCE(reservation_table.quantity, 0)) AS qty'),
            new \Zend_Db_Expr('CASE WHEN main_table.qty + SUM(COALESCE(reservation_table.quantity, 0)) > 0 || SUM(reservation_table.quantity) IS NULL THEN main_table.is_in_stock ELSE 0 END AS is_in_stock')
        ];

        $select = $connection->select()
            ->from(
                ['main_table' => $this->resource->getTableName('cataloginventory_stock_item')],
                $fields
            )->where('main_table.product_id IN (?)', $productIds);

        $joinConditionClause = [
            'main_table.product_id=status_table.product_id',
            'main_table.stock_id=status_table.stock_id',
            'status_table.website_id = ?'
        ];

        $select->joinLeft(
            ['status_table' => $this->resource->getTableName('cataloginventory_stock_status')],
            $connection->quoteInto(
                implode(' AND ', $joinConditionClause),
                $websiteId
            ),
            ['stock_status']
        );

        $select->joinLeft(
            ['product_table' => $this->resource->getTableName('catalog_product_entity')],
            'main_table.product_id=product_table.entity_id'
        );

        $select->joinLeft(
            ['reservation_table' => $this->resource->getTableName('inventory_reservation')],
            'reservation_table.sku=product_table.sku AND main_table.stock_id = reservation_table.stock_id',
            $expressionsToSelect
        );

        $select->group(
            [
                'main_table.product_id',
                'main_table.item_id',
                'main_table.stock_id'
            ]
        );

        return $connection->fetchAssoc($select);
    }

    /**
     * @return int|null
     */
    private function getWebsiteId()
    {
        return $this->stockConfiguration->getDefaultScopeId();
    }
}
