<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\CleanCacheInterface;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Indexer\CacheContext;
use Psr\Log\LoggerInterface;

class CleanCache implements CleanCacheInterface
{
    private Manager $cacheManager;

    private TypeListInterface $cacheTypeList;

    private LoggerInterface $logger;

    public function __construct(
        Manager $cacheManager,
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger
    ) {
        $this->cacheManager =
            $cacheManager;

        $this->cacheTypeList =
            $cacheTypeList;

        $this->logger =
            $logger;
    }

    public function execute()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | CACHE TYPES
            |--------------------------------------------------------------------------
            */

            $types = [

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

                'full_page',

                'translate'
            ];

            /*
            |--------------------------------------------------------------------------
            | CLEAN TYPES
            |--------------------------------------------------------------------------
            */

            foreach ($types as $type) {

                try {

                    $this->cacheTypeList
                        ->cleanType($type);

                } catch (\Throwable $e) {

                    $this->logger->error(
                        sprintf(
                            '[CACHE TYPE: %s] %s',
                            $type,
                            $e->getMessage()
                        )
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | FLUSH CACHE
            |--------------------------------------------------------------------------
            */

            $this->cacheManager
                ->flush($types);

            return json_encode([
                'success' => true,
                'message' => 'Cache pulita'
            ]);

        } catch (\Throwable $e) {

            $this->logger
                ->critical($e);

            return json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}