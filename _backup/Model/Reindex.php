<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ReindexInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Psr\Log\LoggerInterface;

class Reindex implements ReindexInterface
{
    private TypeListInterface $cacheTypeList;

    private LoggerInterface $logger;

    private CollectionFactory $productCollectionFactory;

    private ProductUrlRewriteGenerator $urlRewriteGenerator;

    private UrlPersistInterface $urlPersist;

    public function __construct(
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger,
        CollectionFactory $productCollectionFactory,
        ProductUrlRewriteGenerator $urlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->cacheTypeList =
            $cacheTypeList;

        $this->logger =
            $logger;

        $this->productCollectionFactory =
            $productCollectionFactory;

        $this->urlRewriteGenerator =
            $urlRewriteGenerator;

        $this->urlPersist =
            $urlPersist;
    }

    public function execute(
        string $batchId,
        array $skus = []
    ) {

        $statusDir =
            BP . '/var/log/heron';

        $statusFile =
            $statusDir
            . '/reindex-status-'
            . preg_replace(
                '/[^a-zA-Z0-9\-_]/',
                '',
                $batchId
            )
            . '.json';

        try {

            /*
            |--------------------------------------------------------------------------
            | STATUS DIRECTORY
            |--------------------------------------------------------------------------
            */

            if (!is_dir($statusDir)) {

                mkdir(
                    $statusDir,
                    0777,
                    true
                );
            }

            /*
            |--------------------------------------------------------------------------
            | INITIAL STATUS
            |--------------------------------------------------------------------------
            */

            $this->writeStatus(
                $statusFile,
                true,
                0,
                0
            );

            /*
            |--------------------------------------------------------------------------
            | IMMEDIATE RESPONSE
            |--------------------------------------------------------------------------
            */

            ignore_user_abort(true);

            set_time_limit(0);

            header('Content-Type: application/json');

            echo json_encode([
                'success' => true,
                'started' => true,
                'batchId' => $batchId
            ]);

            if (function_exists('fastcgi_finish_request')) {

                fastcgi_finish_request();

            } else {

                @ob_end_flush();

                flush();
            }

            /*
            |--------------------------------------------------------------------------
            | TOTAL PRODUCTS
            |--------------------------------------------------------------------------
            */

            $totalCollection =
                $this->productCollectionFactory
                    ->create();

            if (!empty($skus)) {

                $totalCollection
                    ->addFieldToFilter(
                        'sku',
                        [
                            'in' => $skus
                        ]
                    );
            }

            $total =
                (int)$totalCollection
                    ->getSize();

            unset($totalCollection);

            /*
            |--------------------------------------------------------------------------
            | PRODUCTS
            |--------------------------------------------------------------------------
            */

            $processed = 0;

            $pageSize = 500;

            $currentPage = 1;

            do {

                $collection =
                    $this->productCollectionFactory
                        ->create();

                $collection
                    ->addAttributeToSelect([
                        'name',
                        'url_key'
                    ])
                    ->setPageSize($pageSize)
                    ->setCurPage($currentPage);

                if (!empty($skus)) {

                    $collection
                        ->addFieldToFilter(
                            'sku',
                            [
                                'in' => $skus
                            ]
                        );
                }

                foreach ($collection as $product) {

                    try {

                        /*
                        |--------------------------------------------------------------------------
                        | URL REWRITE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $product->getUrlKey()
                        ) {

                            $rewrites =
                                $this->urlRewriteGenerator
                                    ->generate($product);

                            if (!empty($rewrites)) {

                                $this->urlPersist
                                    ->replace($rewrites);
                            }
                        }

                    } catch (\Throwable $e) {

                        $this->logger->error(
                            sprintf(
                                '[SKU: %s] %s',
                                $product->getSku(),
                                $e->getMessage()
                            )
                        );
                    }

                    $processed++;

                    /*
                    |--------------------------------------------------------------------------
                    | STATUS UPDATE
                    |--------------------------------------------------------------------------
                    */

                    $this->writeStatus(
                        $statusFile,
                        true,
                        $processed,
                        $total
                    );
                }

                $collection->clear();

                unset($collection);

                gc_collect_cycles();

                $currentPage++;

            } while (
                $currentPage
                <= ceil($total / $pageSize)
            );

            /*
            |--------------------------------------------------------------------------
            | CLI REINDEX
            |--------------------------------------------------------------------------
            */

            $command =
                'cd '
                . BP
                . ' && php bin/magento indexer:reindex '
                . 'catalog_category_product '
                . 'catalog_product_category '
                . 'catalog_product_price '
                . 'catalog_product_attribute '
                . 'cataloginventory_stock '
                . 'catalogsearch_fulltext '
                . '> /dev/null 2>&1';

            exec($command);


            /*
            |--------------------------------------------------------------------------
            | CLEAR IMAGE CACHE
            |--------------------------------------------------------------------------
            */

            $mediaCache =
                BP . '/pub/media/catalog/product/cache';

            if (is_dir($mediaCache)) {
                exec(
                    'rm -rf '
                    . escapeshellarg($mediaCache)
                );
            }
            
            /*
            |--------------------------------------------------------------------------
            | CACHE CLEAN
            |--------------------------------------------------------------------------
            */

            $cacheTypes = [

                'config',

                'layout',

                'block_html',

                'collections',

                'reflection',

                'db_ddl',

                'compiled_config',

                'eav',

                'customer_notification',

                'config_integration',

                'config_integration_api',

                'full_page'
            ];

            foreach ($cacheTypes as $cacheType) {

                try {

                    $this->cacheTypeList
                        ->cleanType(
                            $cacheType
                        );

                } catch (\Throwable $e) {

                    $this->logger->error(
                        sprintf(
                            '[CACHE: %s] %s',
                            $cacheType,
                            $e->getMessage()
                        )
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | COMPLETE STATUS
            |--------------------------------------------------------------------------
            */

            file_put_contents(
                $statusFile,
                json_encode([
                    'running' => false,
                    'processed' => $total,
                    'total' => $total,
                    'percent' => 100,
                    'updated_at' =>
                        date(
                            'Y-m-d H:i:s'
                        )
                ])
            );

        } catch (\Throwable $e) {

            $this->logger->critical($e);

            file_put_contents(
                $statusFile,
                json_encode([
                    'running' => false,
                    'error' => true,
                    'message' =>
                        $e->getMessage(),
                    'updated_at' =>
                        date(
                            'Y-m-d H:i:s'
                        )
                ])
            );
        }
    }

    /**
     * @param string $statusFile
     * @param bool $running
     * @param int $processed
     * @param int $total
     * @return void
     */
    private function writeStatus(
        string $statusFile,
        bool $running,
        int $processed,
        int $total
    ): void {

        file_put_contents(
            $statusFile,
            json_encode([
                'running' => $running,
                'processed' => $processed,
                'total' => $total,
                'percent' =>
                    $total > 0
                        ? round(
                            (
                                $processed
                                / $total
                            ) * 100,
                            2
                        )
                        : 0,
                'updated_at' =>
                    date(
                        'Y-m-d H:i:s'
                    )
            ])
        );
    }
}
