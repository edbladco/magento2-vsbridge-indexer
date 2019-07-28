<?php
/**
 * @package  Divante\VsbridgeIndexerCatalog
 * @author Agata Firlejczyk <afirlejczyk@divante.pl>
 * @copyright 2019 Divante Sp. z o.o.
 * @license See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product;

use Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\AttributeDataProvider;
use Divante\VsbridgeIndexerCore\Api\DataProviderInterface;
use Divante\VsbridgeIndexerCore\Indexer\DataFilter;
use Divante\VsbridgeIndexerCatalog\Api\Data\CatalogConfigurationInterface;
use Divante\VsbridgeIndexerCatalog\Api\SlugGeneratorInterface;
use Divante\VsbridgeIndexerCatalog\Model\ProductUrlPathGenerator;
use Magento\Framework\App\ResourceConnection;

/**
 * Class AttributeData
 */
class AttributeData implements DataProviderInterface
{
    /**
     * @var AttributeDataProvider
     */
    private $resourceModel;

    /**
     * @var DataFilter
     */
    private $dataFilter;

    /**
     * @var CatalogConfigurationInterface
     */
    private $settings;

    /**
     * @var SlugGeneratorInterface
     */
    private $slugGenerator;

    /**
     * @var AttributeDataProvider
     */
    private $productUrlPathGenerator;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * AttributeData constructor.
     *
     * @param CatalogConfigurationInterface $configSettings
     * @param SlugGeneratorInterface $slugGenerator
     * @param ProductUrlPathGenerator $productUrlPathGenerator
     * @param DataFilter $dataFilter
     * @param AttributeDataProvider $resourceModel
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CatalogConfigurationInterface $configSettings,
        SlugGeneratorInterface $slugGenerator,
        ProductUrlPathGenerator $productUrlPathGenerator,
        DataFilter $dataFilter,
        AttributeDataProvider $resourceModel,
        ResourceConnection $resourceConnection
    ) {
        $this->slugGenerator = $slugGenerator;
        $this->settings = $configSettings;
        $this->resourceModel = $resourceModel;
        $this->dataFilter = $dataFilter;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param array $indexData
     * @param int   $storeId
     *
     * @return array
     * @throws \Exception
     */
    public function addData(array $indexData, $storeId)
    {
        $attributes = $this->resourceModel->loadAttributesData($storeId, array_keys($indexData));

        foreach ($attributes as $entityId => $attributesData) {
            $productData = array_merge($indexData[$entityId], $attributesData);
            $productData = $this->applySlug($productData);
            $indexData[$entityId] = $productData;
        }

        $sortTables = [
            'amasty_sorting_most_viewed' => [
                'name' => 'views_num',
                'alias' => 'amasty_sorting_most_viewed'
            ],
            'amasty_sorting_bestsellers' => [
                'name' => 'qty_ordered',
                'alias' => 'amasty_sorting_bestsellers'
            ]
        ];

        $productIds = array_keys($indexData);

        foreach ($sortTables as $table => $column) {
            $connection = $this->resourceConnection->getConnection();

            $fields = [$column['alias'] => $column['name'], 'product_id' => 'product_id'];
            $select = $connection->select()
                ->from(
                    ['main_table' => $this->resourceConnection->getTableName($table)],
                    $fields
                )
                ->where('main_table.product_id IN (?)', $productIds)
                ->where('main_table.store_id = ?', $storeId);

            $sortByValues = $connection->fetchAssoc($select);

            foreach ($sortByValues as $sortByValue) {
                $indexData[$sortByValue['product_id']][$column['alias']] = (int) $sortByValue[$column['alias']];
            }

            foreach ($productIds as $productId) {
                if (!array_key_exists($column['alias'], $indexData[$productId])) {
                    $indexData[$productId][$column['alias']] = 0;
                }
            }
        }

        $attributes = null;
        $indexData = $this->productUrlPathGenerator->addUrlPath($indexData, $storeId);

        return $indexData;
    }

    /**
     * @param array $productData
     *
     * @return array
     */
    private function applySlug(array $productData): array
    {
        $entityId = $productData['id'];

        if ($this->settings->useMagentoUrlKeys()) {
            $productData['slug'] = $productData['url_key'];
        } else {
            $text = $productData['name'];

            if ($this->settings->useUrlKeyToGenerateSlug()) {
                $text = $productData['url_key'];
            }

            $slug = $this->slugGenerator->generate($text, $entityId);
            $productData['slug'] = $slug;
            $productData['url_key'] = $slug;
        }

        return $productData;
    }
}
