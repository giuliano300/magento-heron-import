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
        $this->resource = $resource;
        $this->logger = $logger;
    }

    public function execute()
    {
        $connection = $this->resource->getConnection();

        try {
            $productTable = $this->resource->getTableName('catalog_product_entity');
            $urlRewriteTable = $this->resource->getTableName('url_rewrite');
            $eavEntityStoreTable = $this->resource->getTableName('eav_entity_store');

            $connection->query('SET FOREIGN_KEY_CHECKS=1');

            $totalProducts = (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM {$productTable}"
            );

            $this->logger->info("[DELETE PRODUCTS] Prodotti trovati: {$totalProducts}");

            if ($totalProducts === 0) {
                return json_encode([
                    'success' => true,
                    'message' => 'Nessun prodotto da eliminare',
                    'deleted' => 0
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CANCELLA SOLO I PRODOTTI
            |--------------------------------------------------------------------------
            | Le tabelle EAV collegate vengono pulite dalle FK ON DELETE CASCADE.
            |--------------------------------------------------------------------------
            */

            $connection->query("DELETE FROM {$productTable}");

            /*
            |--------------------------------------------------------------------------
            | CANCELLA URL REWRITE PRODOTTI
            |--------------------------------------------------------------------------
            */

            if ($connection->isTableExists($urlRewriteTable)) {
                $connection->delete(
                    $urlRewriteTable,
                    ['entity_type = ?' => 'product']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RESET EAV ENTITY STORE PRODUCT
            |--------------------------------------------------------------------------
            */

            if ($connection->isTableExists($eavEntityStoreTable)) {
                $connection->delete(
                    $eavEntityStoreTable,
                    ['entity_type_id = ?' => 4]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RESET AUTO INCREMENT
            |--------------------------------------------------------------------------
            */

            try {
                $connection->query("ALTER TABLE {$productTable} AUTO_INCREMENT = 1");
            } catch (\Throwable $e) {
                $this->logger->warning('[AUTO_INCREMENT] ' . $e->getMessage());
            }

            /*
            |--------------------------------------------------------------------------
            | PULIZIA SEQUENCE PRODUCT
            |--------------------------------------------------------------------------
            */

            $this->cleanProductSequences($connection);

            /*
            |--------------------------------------------------------------------------
            | REINDEX / CACHE
            |--------------------------------------------------------------------------
            */

            $this->runMagentoCommand('indexer:reindex');
            $this->runMagentoCommand('cache:flush');

            return json_encode([
                'success' => true,
                'message' => 'Prodotti eliminati correttamente',
                'deleted' => $totalProducts
            ]);

        } catch (\Throwable $e) {

            $this->logger->critical('[DELETE PRODUCTS ERROR] ' . $e->getMessage());

            return json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function cleanProductSequences($connection): void
    {
        try {
            $tables = $connection->fetchCol("SHOW TABLES LIKE '%sequence_product_%'");

            foreach ($tables as $table) {
                $connection->query("DELETE FROM {$table}");
                $this->logger->info("[SEQUENCE CLEANED] {$table}");
            }

        } catch (\Throwable $e) {
            $this->logger->warning('[SEQUENCE ERROR] ' . $e->getMessage());
        }
    }

    private function runMagentoCommand(string $command): void
    {
        try {
            $root = BP;
            $cmd = "php {$root}/bin/magento {$command} 2>&1";

            $output = shell_exec($cmd);

            $this->logger->info("[MAGENTO COMMAND] {$cmd}");
            $this->logger->info("[MAGENTO OUTPUT] " . $output);

        } catch (\Throwable $e) {
            $this->logger->warning("[MAGENTO COMMAND ERROR] {$command}: " . $e->getMessage());
        }
    }
}