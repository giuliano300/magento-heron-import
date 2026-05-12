<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ReindexInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Indexer\IndexerRegistry;
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

    private IndexerRegistry $indexerRegistry;

    public function __construct(
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger,
        CollectionFactory $productCollectionFactory,
        ProductUrlRewriteGenerator $urlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        IndexerRegistry $indexerRegistry
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

        $this->indexerRegistry =
            $indexerRegistry;
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
            | SKUS
            |--------------------------------------------------------------------------
            */

            $decodedSkus =
                $skus;

            /*
            |--------------------------------------------------------------------------
            | TOTAL PRODUCTS
            |--------------------------------------------------------------------------
            */

            $totalCollection =
                $this->productCollectionFactory
                    ->create();

            if (!empty($decodedSkus)) {

                $totalCollection
                    ->addFieldToFilter(
                        'sku',
                        [
                            'in' => $decodedSkus
                        ]
                    );
            }

            $total =
                (int)$totalCollection
                    ->getSize();

            unset($totalCollection);

            $processed = 0;

            /*
            |--------------------------------------------------------------------------
            | UPDATE TOTAL
            |--------------------------------------------------------------------------
            */

            $this->writeStatus(
                $statusFile,
                true,
                $processed,
                $total
            );

            /*
            |--------------------------------------------------------------------------
            | PRODUCT IDS
            |--------------------------------------------------------------------------
            */

            $productIds = [];

            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */

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

                if (!empty($decodedSkus)) {

                    $collection
                        ->addFieldToFilter(
                            'sku',
                            [
                                'in' => $decodedSkus
                            ]
                        );
                }

                foreach (
                    $collection
                    as $product
                ) {

                    try {

                        $productIds[] =
                            (int)$product->getId();

                        /*
                        |--------------------------------------------------------------------------
                        | URL KEY CHECK
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !$product->getUrlKey()
                        ) {

                            $processed++;

                            $this->writeStatus(
                                $statusFile,
                                true,
                                $processed,
                                $total
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | URL REWRITES
                        |--------------------------------------------------------------------------
                        */

                        $rewrites =
                            $this->urlRewriteGenerator
                                ->generate($product);

                        if (
                            !empty($rewrites)
                        ) {

                            $this->urlPersist
                                ->replace(
                                    $rewrites
                                );
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

                /*
                |--------------------------------------------------------------------------
                | FREE MEMORY
                |--------------------------------------------------------------------------
                */

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
            | INDEXERS
            |--------------------------------------------------------------------------
            */

            if (!empty($productIds)) {

                $chunks =
                    array_chunk(
                        $productIds,
                        1000
                    );

                $indexers = [

                    'catalog_category_product',

                    'catalog_product_category',

                    'cataloginventory_stock',

                    'catalogsearch_fulltext'
                ];

                foreach (
                    $indexers
                    as $indexerId
                ) {

                    try {

                        $indexer =
                            $this->indexerRegistry
                                ->get(
                                    $indexerId
                                );

                        /*
                        |--------------------------------------------------------------------------
                        | CATEGORY INDEXERS
                        |--------------------------------------------------------------------------
                        */

                        if (
                            in_array(
                                $indexerId,
                                [
                                    'catalog_category_product',
                                    'catalog_product_category',
                                     'catalogsearch_fulltext'
                                ]
                            )
                        ) {

                            $indexer
                                ->reindexAll();

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | PARTIAL INDEXERS
                        |--------------------------------------------------------------------------
                        */

                        foreach (
                            $chunks
                            as $chunk
                        ) {

                            $indexer
                                ->reindexList(
                                    $chunk
                                );
                        }

                    } catch (\Throwable $e) {

                        $this->logger->error(
                            sprintf(
                                '[INDEXER: %s] %s',
                                $indexerId,
                                $e->getMessage()
                            )
                        );
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CACHE CLEAN
            |--------------------------------------------------------------------------
            */

            $cacheTypes = [

                'full_page',

                'block_html',

                'collections',

                'reflection'
            ];

            foreach (
                $cacheTypes
                as $cacheType
            ) {

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