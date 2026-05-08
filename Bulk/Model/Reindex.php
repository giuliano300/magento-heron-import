<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ReindexInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlPersistInterface;

class Reindex implements ReindexInterface
{
    private TypeListInterface $cacheTypeList;
    private Pool $cacheFrontendPool;
    private LoggerInterface $logger;
    private CollectionFactory $productCollectionFactory;
    private ProductUrlRewriteGenerator $urlRewriteGenerator;
    private UrlPersistInterface $urlPersist;

    public function __construct(
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        LoggerInterface $logger,
        CollectionFactory $productCollectionFactory,
        ProductUrlRewriteGenerator $urlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->cacheTypeList = $cacheTypeList;
        $this->cacheFrontendPool = $cacheFrontendPool;
        $this->logger = $logger;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->urlRewriteGenerator = $urlRewriteGenerator;
        $this->urlPersist = $urlPersist;
    }

    public function execute(?string $skus = null)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | DECODE SKUS
            |--------------------------------------------------------------------------
            */

            $decodedSkus = [];

            if (!empty($skus)) {

                $decodedSkus = json_decode(
                    $skus,
                    true
                );

                if (!is_array($decodedSkus)) {

                    throw new \Exception(
                        'SKU non validi'
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | STOCK REINDEX
            |--------------------------------------------------------------------------
            */

            $basePath = escapeshellarg(BP);

            $output = [];
            $returnCode = 0;

            exec(
                'cd '
                . $basePath
                . ' && php bin/magento indexer:reindex cataloginventory_stock 2>&1',
                $output,
                $returnCode
            );

            $this->logger->info(
                implode("\n", $output)
            );

            /*
            |--------------------------------------------------------------------------
            | PRODUCT COLLECTION
            |--------------------------------------------------------------------------
            */

            $collection =
                $this->productCollectionFactory
                    ->create();

            $collection
                ->addAttributeToSelect([
                    'name',
                    'url_key'
                ]);

            /*
            |--------------------------------------------------------------------------
            | FILTER SKU
            |--------------------------------------------------------------------------
            */

            if (!empty($decodedSkus)) {

                $collection->addFieldToFilter(
                    'sku',
                    [
                        'in' => $decodedSkus
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | URL REWRITE GENERATION
            |--------------------------------------------------------------------------
            */

            foreach ($collection as $product) {

                try {

                    $rewrites =
                        $this->urlRewriteGenerator
                            ->generate($product);

                    if (!empty($rewrites)) {

                        $this->urlPersist
                            ->replace($rewrites);
                    }

                } catch (\Throwable $e) {

                    $this->logger->error(
                        sprintf(
                            '[%s] %s',
                            $product->getSku(),
                            $e->getMessage()
                        )
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CACHE FLUSH
            |--------------------------------------------------------------------------
            */

            exec(
                'cd '
                . $basePath
                . ' && php bin/magento cache:flush 2>&1',
                $output,
                $returnCode
            );

            /*
            |--------------------------------------------------------------------------
            | CACHE CLEAN
            |--------------------------------------------------------------------------
            */

            $types = [
                'full_page',
                'block_html'
            ];

            foreach ($types as $type) {

                $this->cacheTypeList
                    ->cleanType($type);
            }

            foreach (
                $this->cacheFrontendPool
                as $cacheFrontend
            ) {

                $cacheFrontend
                    ->getBackend()
                    ->clean();
            }

            return json_encode([
                'success' => true,
                'products' =>
                    !empty($decodedSkus)
                        ? count($decodedSkus)
                        : 'all'
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