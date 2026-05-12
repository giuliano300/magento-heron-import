<?php

namespace Heron\Bulk\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class CleanIndex
{
    private ResourceConnection $resource;

    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {

            $connection =
                $this->resource->getConnection();

            $tables = [

                'catalog_category_product_cl',

                'catalog_product_category_cl',

                'catalog_product_price_cl',

                'cataloginventory_stock_cl',

                'catalogsearch_fulltext_cl',

                'amasty_label_cl',

                'amasty_mostviewed_product_rule_cl',

                'amasty_reports_product_rule_cl',

                'amasty_xlanding_product_page_cl',

                'merchandiser_product_attribute_cl',

                'amasty_product_order_attribute_cl',

                'catalogrule_product_cl',

                'catalog_product_attribute_cl'
            ];
            
            $cleaned = [];

            foreach ($tables as $table) {

                $fullTable =
                    $this->resource
                        ->getTableName($table);

                if (
                    $connection->isTableExists(
                        $fullTable
                    )
                ) {

                    $connection->truncateTable(
                        $fullTable
                    );

                    $cleaned[] = $table;
                }
            }

            return json_encode([
                'success' => true,
                'tables' => $cleaned
            ]);

        } catch (\Throwable $e) {

            $this->logger->critical($e);

            return json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}