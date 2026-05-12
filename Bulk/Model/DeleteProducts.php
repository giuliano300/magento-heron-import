<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\DeleteProductsInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class DeleteProducts implements DeleteProductsInterface
{
    private ResourceConnection $resource;

    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->resource =
            $resource;

        $this->logger =
            $logger;
    }

    public function execute()
    {
        $connection =
            $this->resource
                ->getConnection();

        try {

            /*
            |--------------------------------------------------------------------------
            | TRANSACTION
            |--------------------------------------------------------------------------
            */

            $connection
                ->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | DISABLE FK
            |--------------------------------------------------------------------------
            */

            $connection->query(
                'SET FOREIGN_KEY_CHECKS=0'
            );

            /*
            |--------------------------------------------------------------------------
            | TABLES
            |--------------------------------------------------------------------------
            */

            $tables = [

                /*
                |--------------------------------------------------------------------------
                | PRODUCTS
                |--------------------------------------------------------------------------
                */

                'catalog_product_entity',
                'catalog_product_entity_datetime',
                'catalog_product_entity_decimal',
                'catalog_product_entity_gallery',
                'catalog_product_entity_int',
                'catalog_product_entity_media_gallery',
                'catalog_product_entity_media_gallery_value',
                'catalog_product_entity_media_gallery_value_to_entity',
                'catalog_product_entity_text',
                'catalog_product_entity_tier_price',
                'catalog_product_entity_varchar',

                /*
                |--------------------------------------------------------------------------
                | RELATIONS
                |--------------------------------------------------------------------------
                */

                'catalog_product_super_attribute',
                'catalog_product_super_attribute_label',
                'catalog_product_super_link',
                'catalog_product_relation',
                'catalog_product_website',
                'catalog_product_link',
                'catalog_product_link_attribute',
                'catalog_product_link_attribute_decimal',
                'catalog_product_link_attribute_int',
                'catalog_product_link_attribute_varchar',

                /*
                |--------------------------------------------------------------------------
                | CATEGORIES
                |--------------------------------------------------------------------------
                */

                'catalog_category_product',
                'catalog_category_product_index',
                'catalog_category_product_index_replica',
                'catalog_category_product_cl',

                /*
                |--------------------------------------------------------------------------
                | INVENTORY
                |--------------------------------------------------------------------------
                */

                'cataloginventory_stock_item',
                'cataloginventory_stock_status',
                'inventory_source_item',
                'inventory_reservation',

                /*
                |--------------------------------------------------------------------------
                | INDEXES
                |--------------------------------------------------------------------------
                */

                'catalog_product_index_eav',
                'catalog_product_index_eav_decimal',
                'catalog_product_index_price',
                'catalog_product_index_price_idx',
                'catalog_product_index_price_tmp',
                'catalog_product_index_tier_price',
                'catalog_product_index_website',

                /*
                |--------------------------------------------------------------------------
                | RULES
                |--------------------------------------------------------------------------
                */

                'catalogrule_product',
                'catalogrule_product_price',

                /*
                |--------------------------------------------------------------------------
                | SEARCH
                |--------------------------------------------------------------------------
                */

                'catalogsearch_fulltext_scope1',

                
                /*
                |--------------------------------------------------------------------------
                | CHANGELOGS
                |--------------------------------------------------------------------------
                */

                'catalog_product_price_cl',
                'catalog_product_attribute_cl',
                'catalog_product_category_cl',
                'cataloginventory_stock_cl',
                'catalogsearch_fulltext_cl',

                'amasty_label_cl',
                'amasty_mostviewed_product_rule_cl',
                'amasty_reports_product_rule_cl',
                'amasty_xlanding_product_page_cl',
                'merchandiser_product_attribute_cl',
                'amasty_product_order_attribute_cl',
                'catalogrule_product_cl'
            ];

            /*
            |--------------------------------------------------------------------------
            | TRUNCATE TABLES
            |--------------------------------------------------------------------------
            */

            foreach ($tables as $table) {

                try {

                    $fullTableName =
                        $this->resource
                            ->getTableName($table);

                    $connection
                        ->truncateTable(
                            $fullTableName
                        );

                } catch (\Throwable $e) {

                    $this->logger->error(
                        sprintf(
                            '[TABLE: %s] %s',
                            $table,
                            $e->getMessage()
                        )
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | URL REWRITES
            |--------------------------------------------------------------------------
            */

            $connection->delete(
                $this->resource->getTableName(
                    'url_rewrite'
                ),
                [
                    'entity_type = ?' => 'product'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | SEQUENCE
            |--------------------------------------------------------------------------
            */

            try {

                $connection->truncateTable(
                    $this->resource->getTableName(
                        'sequence_product_1'
                    )
                );

            } catch (\Throwable $e) {

                $this->logger->error(
                    $e->getMessage()
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ENABLE FK
            |--------------------------------------------------------------------------
            */

            $connection->query(
                'SET FOREIGN_KEY_CHECKS=1'
            );

            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $connection
                ->commit();

            return json_encode([
                'success' => true,
                'message' => 'Tutti i prodotti eliminati'
            ]);

        } catch (\Throwable $e) {

            $connection
                ->rollBack();

            $this->logger
                ->critical($e);

            return json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}