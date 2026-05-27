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
        | START TRANSACTION
        |--------------------------------------------------------------------------
        */

        $connection->beginTransaction();

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
            'catalog_product_entity_int',
            'catalog_product_entity_text',
            'catalog_product_entity_tier_price',
            'catalog_product_entity_varchar',

            /*
            |--------------------------------------------------------------------------
            | MEDIA
            |--------------------------------------------------------------------------
            */

            'catalog_product_entity_media_gallery',
            'catalog_product_entity_media_gallery_value',
            'catalog_product_entity_media_gallery_value_to_entity',
            'catalog_product_entity_media_gallery_value_video',

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
            | CATEGORY
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
            'catalogrule_product_cl',

            /*
            |--------------------------------------------------------------------------
            | SEARCH
            |--------------------------------------------------------------------------
            */

            'catalogsearch_fulltext_scope1',
            'catalogsearch_fulltext_cl',

            /*
            |--------------------------------------------------------------------------
            | CHANGELOGS
            |--------------------------------------------------------------------------
            */

            'catalog_product_price_cl',
            'catalog_product_attribute_cl',
            'catalog_product_category_cl',
            'cataloginventory_stock_cl',

            /*
            |--------------------------------------------------------------------------
            | AMASTY
            |--------------------------------------------------------------------------
            */

            'amasty_label_cl',
            'amasty_mostviewed_product_rule_cl',
            'amasty_reports_product_rule_cl',
            'amasty_xlanding_product_page_cl',
            'amasty_product_order_attribute_cl',

            /*
            |--------------------------------------------------------------------------
            | MERCHANDISER
            |--------------------------------------------------------------------------
            */

            'merchandiser_product_attribute_cl'
        ];

        /*
        |--------------------------------------------------------------------------
        | DELETE TABLES
        |--------------------------------------------------------------------------
        */

        foreach ($tables as $table) {

            try {

                $fullTableName =
                    $this->resource
                        ->getTableName($table);

                /*
                |--------------------------------------------------------------------------
                | CHECK TABLE EXISTS
                |--------------------------------------------------------------------------
                */

                if (!$connection->isTableExists($fullTableName)) {

                    $this->logger->warning(
                        "[TABLE NOT EXISTS] {$table}"
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | DELETE
                |--------------------------------------------------------------------------
                */

                $connection->query(
                    "DELETE FROM {$fullTableName}"
                );

                /*
                |--------------------------------------------------------------------------
                | LOG COUNT
                |--------------------------------------------------------------------------
                */

                $count = $connection->fetchOne(
                    "SELECT COUNT(*) FROM {$fullTableName}"
                );

                $this->logger->info(
                    "[TABLE {$table}] RECORDS LEFT: {$count}"
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
        | URL REWRITE
        |--------------------------------------------------------------------------
        */

        try {

            $connection->delete(
                $this->resource->getTableName(
                    'url_rewrite'
                ),
                [
                    'entity_type = ?' => 'product'
                ]
            );

        } catch (\Throwable $e) {

            $this->logger->error(
                '[URL REWRITE] ' . $e->getMessage()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RESET AUTO INCREMENT
        |--------------------------------------------------------------------------
        */

        try {

            $connection->query(
                'ALTER TABLE catalog_product_entity AUTO_INCREMENT = 1'
            );

        } catch (\Throwable $e) {

            $this->logger->error(
                '[AUTO_INCREMENT] ' . $e->getMessage()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CLEAN ENTITY STORE
        |--------------------------------------------------------------------------
        */

        try {

            $connection->query(
                'DELETE FROM eav_entity_store WHERE entity_type_id = 4'
            );

        } catch (\Throwable $e) {

            $this->logger->error(
                '[EAV ENTITY STORE] ' . $e->getMessage()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SEQUENCE
        |--------------------------------------------------------------------------
        */

        try {

            $sequenceTable =
                $this->resource
                    ->getTableName(
                        'sequence_product_1'
                    );

            if (
                $connection->isTableExists(
                    $sequenceTable
                )
            ) {

                $connection->query(
                    "DELETE FROM {$sequenceTable}"
                );
            }

        } catch (\Throwable $e) {

            $this->logger->error(
                '[SEQUENCE] ' . $e->getMessage()
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

        $connection->commit();

        /*
        |--------------------------------------------------------------------------
        | REINDEX
        |--------------------------------------------------------------------------
        */

        try {

            shell_exec(
                'php bin/magento indexer:reindex'
            );

            shell_exec(
                'php bin/magento cache:flush'
            );

        } catch (\Throwable $e) {

            $this->logger->error(
                '[REINDEX] ' . $e->getMessage()
            );
        }

        return json_encode([
            'success' => true,
            'message' => 'Tutti i prodotti eliminati'
        ]);

    } catch (\Throwable $e) {

        $connection->rollBack();

        $this->logger->critical(
            $e
        );

        return json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
}