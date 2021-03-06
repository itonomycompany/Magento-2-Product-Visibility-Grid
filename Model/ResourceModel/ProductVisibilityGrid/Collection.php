<?php
/**
 * Created by PhpStorm.
 * User: benvansteenbergen
 * Date: 03/08/2018
 * Time: 11:20
 */

namespace Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid;

use Magento\Store\Model\Store;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection as DataCollection;
use Magento\Catalog\Model\Layer\Category\CollectionFilter;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogInventory\Helper\Stock;

class Collection extends DataCollection
{
    protected $_idFieldName = 'entity_id';
    protected $storeId = 0;
    protected $attributes = array('name', 'status', 'visibility');
    protected $productCollection;
    protected $categoryRepository;
    protected $stockHelper;

    /**
     * Maps field aliases to real fields.
     *
     * @var array
     */
    protected $_map = array('fields' => array(
        'entity_id' => 'main_table.entity_id',
        'type_id' => 'main_table.type_id',
        'sku' => 'main_table.sku',
        'visibility' => 'flat_table.visibility',
        'status' => 'product_status.value'
    ));

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected $curl;
    protected $collectionFilter;

    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactoryInterface $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\HTTP\Client\Curl $curl,
        ProductCollection $productCollection,
        CollectionFilter $collectionFilter,
        CategoryRepositoryInterface $categoryRepository,
        Stock $stockHelper,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null
    ) {

        $this->_init(
            '\Magento\Catalog\Model\Product',
            'Magento\Catalog\Model\ResourceModel\Product'
        );

        $this->_mainTable = $this->getTable('catalog_product_entity');
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);

        // Set store
        $this->curl = $curl;
        $this->productCollection = $productCollection;
        $this->storeManager = $storeManager;
        $this->setStoreId(1);
        $this->collectionFilter = $collectionFilter;
        $this->categoryRepository = $categoryRepository;
        $this->stockHelper = $stockHelper;
    }
    public function _beforeLoad()
    {
        return parent::_beforeLoad();
    }

    /**
     * Prepares the select for this collection.
     *
     * @return Itonomy/ProductVisibilityGrid/Model/Product/Collection
     */
    public function prepareCollection()
    {
        $select = $this->getSelect();
        $store = $this->storeManager->getStore($this->storeId);

        // Check if a store is selected.
        if ($store->getCode() != Store::ADMIN_CODE) {
            // A store is selected.
            // Check if flat table exists.
            $flatTable = $this->getTable('catalog_product_flat') . '_' . $this->storeId;

            if ($this->getConnection()->isTableExists($flatTable)) {
                // Join with flat table, if it exists.
                $select->joinLeft(
                    array('flat_table' => $flatTable),
                    'flat_table.entity_id = main_table.entity_id',
                    array(
                        'in_flat_table' => new \Zend_Db_Expr('flat_table.entity_id IS NOT NULL'),
                        //'visibility' => new \Zend_Db_Expr('COALESCE(flat_table.visibility, ' . Visibility::VISIBILITY_NOT_VISIBLE . ')')
                    )
                );
            } else {
                // Add columns in_flat_table = false and visibility = not visible, otherwise.
                $select->columns(array(
                    'in_flat_table' => new \Zend_Db_Expr('false'),
                    //'visibility' => new \Zend_Db_Expr(Visibility::VISIBILITY_NOT_VISIBLE)
                ));
            }

            // Join with product website link table.
            $select->joinLeft(
                array('product_website' => $this->getTable('catalog_product_website')),
                'product_website.product_id = main_table.entity_id'
                . ' AND product_website.website_id = \'' . $store->getWebsiteId() . '\'',
                array('in_website' => new \Zend_Db_Expr('product_website.product_id IS NOT NULL'))
            );

            // Join with category product link table.
            $select->joinLeft(
                array('category_product' => $this->getTable('catalog_category_product_index_store' . $store->getId())),
                'category_product.product_id = main_table.entity_id'
                . ' AND category_product.category_id = \'' . $store->getRootCategoryId() . '\''
                . ' AND category_product.store_id = \'' . $this->storeId . '\''
                . ' AND category_product.visibility > \'' . Visibility::VISIBILITY_NOT_VISIBLE . '\'',
                array('in_category' => new \Zend_Db_Expr('category_product.product_id IS NOT NULL'))
            );

            // Join with stock status table. Default 0 because Magento is not website store differences (default = 0)
            $select->joinLeft(
                array('stock_status' => $this->getTable('cataloginventory_stock_status')),
                'stock_status.product_id = main_table.entity_id'
                . ' AND stock_status.stock_id = \'1\''
                . ' AND stock_status.website_id = \'' . Store::DEFAULT_STORE_ID . '\'',
                array('in_stock' => new \Zend_Db_Expr('COALESCE(stock_status.stock_status, 0)'))
            );

            // Join with price index table.
            $select->joinLeft(
                array('price_index' => $this->getTable('catalog_product_index_price')),
                'price_index.entity_id = main_table.entity_id'
                . ' AND price_index.customer_group_id = \'' . \Magento\Customer\Model\Group::NOT_LOGGED_IN_ID . '\''
                . ' AND price_index.website_id = \'' . $store->getWebsiteId() . '\'',
                array('in_price_index' => new \Zend_Db_Expr('price_index.entity_id IS NOT NULL'))
            );
        } else {
            // Otherwise, join with all flat tables.
            $columns = array();

            // Iterate over all stores.
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = $store->getId();
                $flatTable = $this->getTable('catalog_product_flat') . '_' . $storeId;
                $flatTableAlias = 'flat_table_' . $storeId;

                // Check if the flat table for this store exists.
                if (!$this->getConnection()->isTableExists($flatTable)) {
                    continue;
                }

                // Join with the flat table for this store.
                $select->joinLeft(
                    array($flatTableAlias => $flatTable),
                    $flatTableAlias . '.entity_id = main_table.entity_id',
                    array()
                );

                // Add the value for this flat table.
                $columns[] = $flatTableAlias . '.entity_id IS NOT NULL';
            }

            // The column in_flat_table should be true if the product is in all flat tables, otherwise false.
            $select->columns(array('in_flat_table' => new \Zend_Db_Expr('false OR (' . implode(' AND ', $columns) . ')')));
        }

        // Join other tables.
        foreach ($this->attributes as $attribute) {
            $this->_addAttribute($attribute);
        }

        return $this;
    }

    /**
     * Adds the given attribute to the select.
     *
     * @param $name string Attribute code.
     */
    protected function _addAttribute($name)
    {
        $select = $this->getSelect();
        $attribute = $this->getResource()->getAttribute($name);
        $attributeId = $attribute->getId();
        $attributeType = $attribute->getBackendType();

        $table = 'product_' . $name;
        $tableStore = $table . '_store';

        // Join with default value table for the attribute.
        $select->joinLeft(
            array($table => $this->getTable('catalog_product_entity') . '_' . $attributeType),
            $table . '.entity_id = main_table.entity_id'
            . ' AND ' . $table . '.attribute_id = \'' . $attributeId . '\''
            . ' AND ' . $table . '.store_id = \'' . Store::DEFAULT_STORE_ID . '\'',
            array()
        );

        // Check if a store is selected.
        if ($this->storeId != Store::DEFAULT_STORE_ID) {
            // A store is selected.
            // Join with store value table for the attribute.
            $select->joinLeft(
                array($tableStore => $this->getTable('catalog_product_entity') . '_' . $attributeType),
                $tableStore . '.entity_id = main_table.entity_id'
                . ' AND ' . $tableStore . '.attribute_id = \'' . $attributeId . '\''
                . ' AND ' . $tableStore . '.store_id = \'' . $this->storeId . '\'',
                array()
            );

            // Define a column for the store value with a fallback to the default value.
            $select->columns(array(
                $name => new \Zend_Db_Expr('IF(' . $tableStore . '.value_id IS NOT NULL, ' . $tableStore . '.value, ' . $table . '.value)')
            ));
        } else {
            // No store has been selected.
            // Define a column for the default value.
            $select->columns(array($name => new \Zend_Db_Expr($table . '.value')));

            // Add the field mapping to make _getConditionSql function on this field.
            $this->_map['fields'][$name] = $table . '.value';
        }
    }

    /**
     * Adds a filter on the calculated field 'In Flat Table'.
     *
     * @param $value boolean Value of in_flat_table to filter.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function addInFlatTableFilter($value)
    {
        $select = $this->getSelect();
        $valueCond = $value ? 'IS NOT NULL' : ' IS NULL';

        // Check if a store is selected.
        if ($this->storeId != Store::DEFAULT_STORE_ID) {
            // Check if flat table exists.
            $flatTable = $this->getTable('catalog_product_flat') . '_' . $this->storeId;

            if ($this->getConnection()->isTableExists($flatTable)) {
                // Filter on flat table, if it exists.
                $select->where(new \Zend_Db_Expr('flat_table.entity_id ' . $valueCond));
            }
        } else {
            // Filer on all flat tables, otherwise.
            $columns = array();

            foreach ($this->storeManager->getStores() as $store) {
                $storeId = $store->getId();
                $flatTable = $this->getTable('catalog_product_flat') . '_' . $storeId;
                $flatTableAlias = 'flat_table_' . $storeId;

                if (!$this->getConnection()->isTableExists($flatTable)) {
                    continue;
                }

                $columns[] = $flatTableAlias . '.entity_id ' . $valueCond;
            }

            $select->where(new \Zend_Db_Expr('true AND (' . implode($value ? ' AND ' : ' OR ', $columns) . ')'));
        }

        return $this;
    }

    /**
     * Adds a filter on the calculated field 'In Website'.
     *
     * @param $value boolean Value of in_website to filter.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function addInWebsiteFilter($value)
    {
        $this->getSelect()->where(new \Zend_Db_Expr(
            'product_website.product_id ' . ($value ? 'IS NOT NULL' : ' IS NULL')
        ));

        return $this;
    }

    /**
     * Adds a filter on the calculated field 'In Category'.
     *
     * @param $value boolean Value of in_category to filter.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function addInCategoryFilter($value)
    {
        $this->getSelect()->where(new \Zend_Db_Expr(
            'category_product.product_id ' . ($value ? 'IS NOT NULL' : ' IS NULL')
        ));

        return $this;
    }

    /**
     * Adds a filter on the calculated field 'In Price Index'.
     *
     * @param $value boolean Value of in_price_index to filter.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function addInPriceIndexFilter($value)
    {
        $this->getSelect()->where(new \Zend_Db_Expr(
            'price_index.entity_id ' . ($value ? 'IS NOT NULL' : ' IS NULL')
        ));

        return $this;
    }

    /**
     * Adds a filter on the calculated field 'In Stock'.
     *
     * @param $value boolean Value of in_stock to filter.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function addInStockFilter($value)
    {
        $this->getSelect()->where(new \Zend_Db_Expr(
            'stock_status.stock_status = \'' . $value . '\''
        ));

        return $this;
    }

    /**
     * Prepares the condition SQL for the given field and condition.
     * Rewrites the condition SQL for attributes to use the store value and default value tables.
     *
     * @param $fieldName string Field name.
     * @param $condition array Condition.
     * @return string Condition SQL.
     */
    protected function _getConditionSql($fieldName, $condition)
    {
        // Rewrite the condition SQL if the field is a joined attributed and a store has been selected.
        if ($this->storeId != Store::DEFAULT_STORE_ID && in_array($fieldName, $this->attributes)) {
            return new \Zend_Db_Expr(
                '(product_' . $fieldName . '_store.value_id IS NOT NULL AND ' . parent::_getConditionSql('product_' . $fieldName . '_store.value', $condition) . ')'
                . ' OR ' . '(product_' . $fieldName . '_store.value_id IS NULL AND ' . parent::_getConditionSql('product_' . $fieldName . '.value', $condition) . ')'
            );
        } else {
            return parent::_getConditionSql($fieldName, $condition);
        }
    }

    /**
     * Sets the store id.
     *
     * @param $storeId int Store id.
     * @return Itonomy\ProductVisibilityGrid\Model\ResourceModel\ProductVisibilityGrid\Collection
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }
    public function _afterLoad()
    {
        $this->processItemData();
        return parent::_afterLoad();
    }

    protected function processItemData()
    {
        $category = $this->categoryRepository->get(2);
        $this->collectionFilter->filter($this->productCollection, $category);
        $this->stockHelper->addIsInStockFilterToCollection($this->productCollection);

        $column = 'is_online_in_cat';

        foreach ($this->getItems() as $item) {

            if(in_array($item->getEntityId(), $this->productCollection->getAllIds())){
                $item[$column] = true;
            } else {
                $item[$column] = false;
            }
        }

        // CURL TO PDP TO SEE IF PRODUCT PAGE IS SHOWING
        /* $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $column = 'is_online';

        foreach ($this->getItems() as $item) {

            $productUrl = sprintf('%s/catalog/product/view/id/%s',$baseUrl, $item->getEntityId());
            $this->curl->get($productUrl);

            if($this->curl->getStatus() != '200'){
                $item[$column] = false;
            } else {
                $item[$column] = true;
            }
        }
        */
        // CURL TO PDP TO SEE IF PRODUCT PAGE IS SHOWING
        /* $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $column = 'is_online';

        foreach ($this->getItems() as $item) {

            $productUrl = sprintf('%s/catalog/product/view/id/%s',$baseUrl, $item->getEntityId());
            $this->curl->get($productUrl);

            if($this->curl->getStatus() != '200'){
                $item[$column] = false;
            } else {
                $item[$column] = true;
            }
        }
        */

    }

    protected function _addColumnFilterToCollection($column)
    {
        $value = $column->getFilter()->getValue();
        if (!isset($value)) {
            parent::_addColumnFilterToCollection($column);
            return $this;
        }

        switch ($column->getId()) {
            case 'in_flat_table':
                $this->getCollection()->addInFlatTableFilter((int)$value);
                break;
            case 'in_website':
                $this->getCollection()->addInWebsiteFilter((int)$value);
                break;
            case 'in_category':
                $this->getCollection()->addInCategoryFilter((int)$value);
                break;
            case 'in_stock':
                $this->getCollection()->addInStockFilter((int)$value);
                break;
            case 'in_price_index':
                $this->getCollection()->addInPriceIndexFilter((int)$value);
                break;
            case 'is_online':
                $this->getCollection();
                break;
            default:
                parent::_addColumnFilterToCollection($column);
                break;
        }

        return $this;
    }


}