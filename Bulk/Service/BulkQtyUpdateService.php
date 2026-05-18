<?php

namespace Heron\Bulk\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Heron\Bulk\Model\Dto\ImportResponse;

class BulkQtyUpdateService
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

    public function update(
        string $products
    ): string {

        $responses = [];

        try {

            /*
            |--------------------------------------------------------------------------
            | JSON
            |--------------------------------------------------------------------------
            */

            $products =
                json_decode(
                    $products,
                    true
                );

            if (
                empty($products) ||
                !is_array($products)
            ) {

                return json_encode([

                    'success' => false,

                    'total' => 0,

                    'items' => [[

                        'sku' => '',

                        'success' => false,

                        'insertType' =>
                            ImportResponse::ERROR,

                        'message' =>
                            'JSON non valido'
                    ]]
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CONNECTION
            |--------------------------------------------------------------------------
            */

            $connection =
                $this->resource->getConnection();

            /*
            |--------------------------------------------------------------------------
            | TABLES
            |--------------------------------------------------------------------------
            */

            $entityTable =
                $this->resource->getTableName(
                    'catalog_product_entity'
                );

            $stockTable =
                $this->resource->getTableName(
                    'cataloginventory_stock_item'
                );

            /*
            |--------------------------------------------------------------------------
            | SKUS
            |--------------------------------------------------------------------------
            */

            $skus =
                array_column(
                    $products,
                    'sku'
                );

            /*
            |--------------------------------------------------------------------------
            | ENTITIES
            |--------------------------------------------------------------------------
            */

            $entities =
                $connection->fetchAssoc(
                    $connection->select()
                        ->from(
                            $entityTable,
                            [
                                'sku',
                                'entity_id'
                            ]
                        )
                        ->where(
                            'sku IN (?)',
                            $skus
                        )
                );

            /*
            |--------------------------------------------------------------------------
            | STOCK ROWS
            |--------------------------------------------------------------------------
            */

            $stockRows = [];

            foreach ($products as $product) {

                $response =
                    new ImportResponse();

                $response->sku =
                    $product['sku'] ?? '';

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | SKU EMPTY
                    |--------------------------------------------------------------------------
                    */

                    if (
                        empty($product['sku'])
                    ) {

                        $response->success =
                            false;

                        $response->insertType =
                            ImportResponse::ERROR;

                        $response->message =
                            'SKU mancante';

                        $responses[] =
                            $response->toArray();

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | PRODUCT NOT FOUND
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !isset(
                            $entities[
                                $product['sku']
                            ]
                        )
                    ) {

                        $response->success =
                            false;

                        $response->insertType =
                            ImportResponse::ERROR;

                        $response->message =
                            'Prodotto non trovato';

                        $responses[] =
                            $response->toArray();

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | ENTITY ID
                    |--------------------------------------------------------------------------
                    */

                    $entityId =
                        (int)$entities[
                            $product['sku']
                        ][
                            'entity_id'
                        ];

                    /*
                    |--------------------------------------------------------------------------
                    | QTY
                    |--------------------------------------------------------------------------
                    */

                    $qty =
                        (float)(
                            $product['qty']
                            ?? 0
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | STOCK ROW
                    |--------------------------------------------------------------------------
                    */

                    $stockRows[] = [

                        'product_id' =>
                            $entityId,

                        'website_id' => 0,

                        'stock_id' => 1,

                        'qty' => $qty,

                        'is_in_stock' =>
                            $qty > 0
                                ? 1
                                : 0,

                        'manage_stock' => 1,

                        'use_config_manage_stock' => 1
                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | RESPONSE
                    |--------------------------------------------------------------------------
                    */

                    $response->success =
                        true;

                    $response->insertType =
                        ImportResponse::UPDATE;

                    $response->message =
                        'Quantità aggiornata';

                } catch (\Throwable $e) {

                    $response->success =
                        false;

                    $response->insertType =
                        ImportResponse::ERROR;

                    $response->message =
                        $e->getMessage();
                }

                $responses[] =
                    $response->toArray();
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE STOCK
            |--------------------------------------------------------------------------
            */

            if (!empty($stockRows)) {

                $connection->insertOnDuplicate(
                    $stockTable,
                    $stockRows,
                    [
                        'qty',
                        'is_in_stock',
                        'manage_stock',
                        'use_config_manage_stock'
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SUCCESS RESPONSE
            |--------------------------------------------------------------------------
            */

            return json_encode([

                'success' => true,

                'total' => count(
                    $responses
                ),

                'items' => $responses
            ]);

        } catch (\Throwable $e) {

            $this->logger->critical($e);

            /*
            |--------------------------------------------------------------------------
            | ERROR RESPONSE
            |--------------------------------------------------------------------------
            */

            return json_encode([

                'success' => false,

                'total' => 0,

                'items' => [[

                    'sku' => '',

                    'success' => false,

                    'insertType' =>
                        ImportResponse::ERROR,

                    'message' =>
                        $e->getMessage()
                ]]
            ]);
        }
    }
}