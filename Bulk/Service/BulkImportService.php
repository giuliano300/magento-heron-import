<?php

namespace Heron\Bulk\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Heron\Bulk\Model\Dto\ImportResponse;

class BulkImportService
{
    private ResourceConnection $resource;
    private LoggerInterface $logger;
    private AttributeRepository $attributeRepository;

    public function __construct(
        ResourceConnection $resource,
        LoggerInterface $logger,
        AttributeRepository $attributeRepository
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
        $this->attributeRepository = $attributeRepository;
    }

    public function import(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $responses = [];

        $connection = $this->resource->getConnection();

        /*
        |--------------------------------------------------------------------------
        | TABLES
        |--------------------------------------------------------------------------
        */

        $entityTable = $this->resource->getTableName(
            'catalog_product_entity'
        );

        $varcharTable = $this->resource->getTableName(
            'catalog_product_entity_varchar'
        );

        $textTable = $this->resource->getTableName(
            'catalog_product_entity_text'
        );

        $decimalTable = $this->resource->getTableName(
            'catalog_product_entity_decimal'
        );

        $intTable = $this->resource->getTableName(
            'catalog_product_entity_int'
        );

        $datetimeTable = $this->resource->getTableName(
            'catalog_product_entity_datetime'
        );

        $websiteTable = $this->resource->getTableName(
            'catalog_product_website'
        );

        $categoryTable = $this->resource->getTableName(
            'catalog_category_product'
        );

        $stockTable = $this->resource->getTableName(
            'cataloginventory_stock_item'
        );

        /*
        |--------------------------------------------------------------------------
        | ATTRIBUTES
        |--------------------------------------------------------------------------
        */

        $nameAttribute =
            $this->attributeRepository
                ->getAttribute('name');

        $priceAttribute =
            $this->attributeRepository
                ->getAttribute('price');

        $specialPriceAttribute =
            $this->attributeRepository
                ->getAttribute('special_price');

        $descriptionAttribute =
            $this->attributeRepository
                ->getAttribute('description');

        $shortDescriptionAttribute =
            $this->attributeRepository
                ->getAttribute('short_description');

        $siteDescriptionAttribute =
            $this->attributeRepository
                ->getAttribute('descrizione_sito');

        $siteShortDescriptionAttribute =
            $this->attributeRepository
                ->getAttribute('descrizione_breve_sito');

        $statusAttribute =
            $this->attributeRepository
                ->getAttribute('status');

        $visibilityAttribute =
            $this->attributeRepository
                ->getAttribute('visibility');

        $urlKeyAttribute =
            $this->attributeRepository
                ->getAttribute('url_key');

        $manufacturerAttribute =
            $this->attributeRepository
                ->getAttribute('manufacturer');

        $supplierAttribute =
            $this->attributeRepository
                ->getAttribute('supplier');

        $specialFromDateAttribute =
            $this->attributeRepository
                ->getAttribute('special_from_date');

        $specialToDateAttribute =
            $this->attributeRepository
                ->getAttribute('special_to_date');

        /*
        |--------------------------------------------------------------------------
        | EXISTING PRODUCTS
        |--------------------------------------------------------------------------
        */

        $skus = array_column($products, 'sku');

        $existingProducts = $connection->fetchAssoc(
            $connection->select()
                ->from(
                    $entityTable,
                    [
                        'sku',
                        'entity_id'
                    ]
                )
                ->where('sku IN (?)', $skus)
        );

        /*
        |--------------------------------------------------------------------------
        | ENTITY INSERT
        |--------------------------------------------------------------------------
        */

        $entityRows = [];

        foreach ($products as $product) {

            $response = new ImportResponse();

            $response->sku =
                $product['sku'] ?? '';

            try {

                if (empty($product['sku'])) {

                    $response->success = false;

                    $response->insertType =
                        ImportResponse::ERROR;

                    $response->message =
                        'SKU mancante';

                    $responses[] =
                        $response->toArray();

                    continue;
                }

                $exists =
                    isset(
                        $existingProducts[
                            $product['sku']
                        ]
                    );

                $entityRows[] = [

                    'attribute_set_id' =>
                        $product['attribute_set_id'] ?? 4,

                    'type_id' =>
                        $product['type_id'] ?? 'simple',

                    'sku' =>
                        $product['sku'],

                    'has_options' => 0,

                    'required_options' => 0,

                    'created_at' =>
                        date('Y-m-d H:i:s'),

                    'updated_at' =>
                        date('Y-m-d H:i:s')
                ];

                $response->success = true;

                $response->insertType =
                    $exists
                        ? ImportResponse::UPDATE
                        : ImportResponse::INSERT;

                $response->message =
                    $exists
                        ? 'Prodotto aggiornato'
                        : 'Prodotto creato';

            } catch (\Throwable $e) {

                $response->success = false;

                $response->insertType =
                    ImportResponse::ERROR;

                $response->message =
                    $e->getMessage();
            }

            $responses[] =
                $response->toArray();
        }

        if (!empty($entityRows)) {

            $connection->insertOnDuplicate(
                $entityTable,
                $entityRows,
                [
                    'attribute_set_id',
                    'type_id',
                    'updated_at'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ENTITY IDS
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
                    ->where('sku IN (?)', $skus)
            );

        /*
        |--------------------------------------------------------------------------
        | EXISTING DECIMAL VALUES
        |--------------------------------------------------------------------------
        */

        $entityIds =
            array_column(
                $entities,
                'entity_id'
            );

        $existingDecimal = [];

        if (!empty($entityIds)) {

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        $decimalTable,
                        [
                            'entity_id',
                            'attribute_id',
                            'value'
                        ]
                    )
                    ->where(
                        'entity_id IN (?)',
                        $entityIds
                    )
            );

            foreach ($rows as $row) {

                $existingDecimal[
                    $row['entity_id']
                ][
                    $row['attribute_id']
                ] = $row['value'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ROWS
        |--------------------------------------------------------------------------
        */

        $varcharRows = [];
        $textRows = [];
        $decimalRows = [];
        $intRows = [];
        $datetimeRows = [];
        $websiteRows = [];
        $categoryRows = [];
        $stockRows = [];

        foreach ($products as $product) {

            if (
                empty($product['sku']) ||
                !isset($entities[$product['sku']])
            ) {
                continue;
            }

            $entityId =
                (int)$entities[
                    $product['sku']
                ][
                    'entity_id'
                ];

            /*
            |--------------------------------------------------------------------------
            | NAME
            |--------------------------------------------------------------------------
            */

            if (isset($product['name'])) {

                $varcharRows[] = [
                    'attribute_id' =>
                        (int)$nameAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' =>
                        (string)$product['name']
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | URL KEY
            |--------------------------------------------------------------------------
            */

            $urlKey = null;

            if (
                !empty($product['name']) &&
                !empty($product['sku'])
            ) {

                $urlKeySource =
                    $product['name']
                    . '-'
                    . $product['sku'];

                $urlKeySource = iconv(
                    'UTF-8',
                    'ASCII//TRANSLIT',
                    $urlKeySource
                );

                $urlKey = preg_replace(
                    '/[^a-zA-Z0-9]+/',
                    '-',
                    $urlKeySource
                );

                $urlKey = preg_replace(
                    '/-+/',
                    '-',
                    $urlKey
                );

                $urlKey = strtolower(
                    trim($urlKey, '-')
                );
            }

            if (!empty($urlKey)) {

                $varcharRows[] = [
                    'attribute_id' =>
                        (int)$urlKeyAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' => $urlKey
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | SUPPLIER
            |--------------------------------------------------------------------------
            */

            if (isset($product['supplier'])) {

                $varcharRows[] = [
                    'attribute_id' =>
                        (int)$supplierAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' =>
                        (string)$product['supplier']
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | DESCRIPTION
            |--------------------------------------------------------------------------
            */

            if (isset($product['description'])) {

                $description =
                    (string)$product['description'];

                $textRows[] = [
                    'attribute_id' =>
                        (int)$descriptionAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' => $description
                ];

                $textRows[] = [
                    'attribute_id' =>
                        (int)$siteDescriptionAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' => $description
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | SHORT DESCRIPTION
            |--------------------------------------------------------------------------
            */

            if (isset($product['short_description'])) {

                $short =
                    (string)$product['short_description'];

                $textRows[] = [
                    'attribute_id' =>
                        (int)$shortDescriptionAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' => $short
                ];

                $textRows[] = [
                    'attribute_id' =>
                        (int)$siteShortDescriptionAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' => $short
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | PRICE
            |--------------------------------------------------------------------------
            */

            if (isset($product['price'])) {

                $newPrice =
                    (float)$product['price'];

                $attributeId =
                    (int)$priceAttribute['attribute_id'];

                $current =
                    $existingDecimal[
                        $entityId
                    ][
                        $attributeId
                    ] ?? null;

                if (
                    $current === null ||
                    (float)$current !== $newPrice
                ) {

                    $decimalRows[] = [
                        'attribute_id' =>
                            $attributeId,

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' => $newPrice
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | SPECIAL PRICE
            |--------------------------------------------------------------------------
            */

            if (isset($product['special_price']) &&
                (float)$product['special_price'] > 0
            ) {

                $newSpecial =
                    (float)$product['special_price'];

                $attributeId =
                    (int)$specialPriceAttribute['attribute_id'];

                $current =
                    $existingDecimal[
                        $entityId
                    ][
                        $attributeId
                    ] ?? null;

                if (
                    $current === null ||
                    (float)$current !== $newSpecial
                ) {

                    $decimalRows[] = [
                        'attribute_id' =>
                            $attributeId,

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' => $newSpecial
                    ];

                    $datetimeRows[] = [
                        'attribute_id' =>
                            (int)$specialFromDateAttribute['attribute_id'],

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' =>
                            date('Y-m-d H:i:s')
                    ];

                    $datetimeRows[] = [
                        'attribute_id' =>
                            (int)$specialToDateAttribute['attribute_id'],

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' =>
                            '2035-12-31 23:59:59'
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            $intRows[] = [
                'attribute_id' =>
                    (int)$statusAttribute['attribute_id'],

                'store_id' => 0,

                'entity_id' => $entityId,

                'value' =>
                    (int)($product['status'] ?? 1)
            ];

            /*
            |--------------------------------------------------------------------------
            | VISIBILITY
            |--------------------------------------------------------------------------
            */

            $intRows[] = [
                'attribute_id' =>
                    (int)$visibilityAttribute['attribute_id'],

                'store_id' => 0,

                'entity_id' => $entityId,

                'value' =>
                    (int)($product['visibility'] ?? 4)
            ];

            /*
            |--------------------------------------------------------------------------
            | MANUFACTURER
            |--------------------------------------------------------------------------
            */

            if (isset($product['manufacturer'])) {

                $intRows[] = [
                    'attribute_id' =>
                        (int)$manufacturerAttribute['attribute_id'],

                    'store_id' => 0,

                    'entity_id' => $entityId,

                    'value' =>
                        (int)$product['manufacturer']
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | STOCK
            |--------------------------------------------------------------------------
            */

            $qty =
                (float)($product['qty'] ?? 0);

            $stockRows[] = [
                'product_id' => $entityId,
                'stock_id' => 1,
                'qty' => $qty,
                'is_in_stock' =>
                    $qty > 0 ? 1 : 0,
                'manage_stock' => 1,
                'use_config_manage_stock' => 1
            ];

            /*
            |--------------------------------------------------------------------------
            | WEBSITES
            |--------------------------------------------------------------------------
            */

            if (!empty($product['website_ids'])) {

                foreach (
                    $product['website_ids']
                    as $websiteId
                ) {

                    $websiteRows[] = [
                        'product_id' => $entityId,
                        'website_id' => (int)$websiteId
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CATEGORIES
            |--------------------------------------------------------------------------
            */

            if (!empty($product['category_ids'])) {

                foreach (
                    $product['category_ids']
                    as $categoryId
                ) {

                    $categoryRows[] = [
                        'category_id' => (int)$categoryId,
                        'product_id' => $entityId,
                        'position' => 0
                    ];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | INSERT VALUES
        |--------------------------------------------------------------------------
        */

        if (!empty($varcharRows)) {

            $connection->insertOnDuplicate(
                $varcharTable,
                $varcharRows,
                ['value']
            );
        }

        if (!empty($textRows)) {

            $connection->insertOnDuplicate(
                $textTable,
                $textRows,
                ['value']
            );
        }

        if (!empty($decimalRows)) {

            $connection->insertOnDuplicate(
                $decimalTable,
                $decimalRows,
                ['value']
            );
        }

        if (!empty($intRows)) {

            $connection->insertOnDuplicate(
                $intTable,
                $intRows,
                ['value']
            );
        }

        if (!empty($datetimeRows)) {

            $connection->insertOnDuplicate(
                $datetimeTable,
                $datetimeRows,
                ['value']
            );
        }

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

        if (!empty($websiteRows)) {

            $connection->insertOnDuplicate(
                $websiteTable,
                $websiteRows,
                []
            );
        }

        if (!empty($categoryRows)) {

            $connection->insertOnDuplicate(
                $categoryTable,
                $categoryRows,
                ['position']
            );
        }

        $this->logger->info(sprintf(
            'Importati %d prodotti',
            count($products)
        ));

        return $responses;
    }
}