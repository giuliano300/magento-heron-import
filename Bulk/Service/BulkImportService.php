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
        
        try 
        {
            /*
            |--------------------------------------------------------------------------
            | TRANSACTION START
            |--------------------------------------------------------------------------
            */

            $connection
                ->beginTransaction();

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
                $this->getRequiredAttribute('name');

            $priceAttribute =
                $this->getRequiredAttribute('price');

            $specialPriceAttribute =
                $this->getOptionalAttribute('special_price');

            $descriptionAttribute =
                $this->getOptionalAttribute('description');

            $shortDescriptionAttribute =
                $this->getOptionalAttribute('short_description');

            $siteDescriptionAttribute =
                $this->getOptionalAttribute('descrizione_sito');

            $siteShortDescriptionAttribute =
                $this->getOptionalAttribute('descrizione_breve_sito');

            $statusAttribute =
                $this->getRequiredAttribute('status');

            $visibilityAttribute =
                $this->getRequiredAttribute('visibility');

            $urlKeyAttribute =
                $this->getOptionalAttribute('url_key');

            $manufacturerAttribute =
                $this->getOptionalAttribute('manufacturer');

            $supplierAttribute =
                $this->getOptionalAttribute('supplier');

            $specialFromDateAttribute =
                $this->getOptionalAttribute('special_from_date');

            $specialToDateAttribute =
                $this->getOptionalAttribute('special_to_date');

            $weightAttribute =
                $this->getOptionalAttribute('weight');


            $taxClassAttribute =
                $this->getOptionalAttribute('tax_class_id');

            $gestTipologiaAttribute =
                $this->getOptionalAttribute('gest_tipologia');


            $gestTipologiaMap =
                $gestTipologiaAttribute === null
                    ? []
                    : $this->getAttributeOptionsMap(
                        'gest_tipologia'
                    );

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
                        $product['sku']
                        . '-'
                        . $product['name'];

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

                if (
                    !empty($urlKey) &&
                    $urlKeyAttribute !== null
                ) {

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

                if (
                    isset($product['supplier']) &&
                    $supplierAttribute !== null
                ) {

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

                if (
                    isset($product['description']) &&
                    $descriptionAttribute !== null
                ) {

                    $description =
                        (string)$product['description'];

                    $textRows[] = [
                        'attribute_id' =>
                            (int)$descriptionAttribute['attribute_id'],

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' => $description
                    ];

                    if ($siteDescriptionAttribute !== null) {

                        $textRows[] = [
                            'attribute_id' =>
                                (int)$siteDescriptionAttribute['attribute_id'],

                            'store_id' => 0,

                            'entity_id' => $entityId,

                            'value' => $description
                        ];
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | SHORT DESCRIPTION
                |--------------------------------------------------------------------------
                */

                if (
                    isset($product['short_description']) &&
                    $shortDescriptionAttribute !== null
                ) {

                    $short =
                        (string)$product['short_description'];

                    $textRows[] = [
                        'attribute_id' =>
                            (int)$shortDescriptionAttribute['attribute_id'],

                        'store_id' => 0,

                        'entity_id' => $entityId,

                        'value' => $short
                    ];

                    if ($siteShortDescriptionAttribute !== null) {

                        $textRows[] = [
                            'attribute_id' =>
                                (int)$siteShortDescriptionAttribute['attribute_id'],

                            'store_id' => 0,

                            'entity_id' => $entityId,

                            'value' => $short
                        ];
                    }
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
                    (float)$product['special_price'] > 0 &&
                    $specialPriceAttribute !== null
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

                        if ($specialFromDateAttribute !== null) {

                            $datetimeRows[] = [
                                'attribute_id' =>
                                    (int)$specialFromDateAttribute['attribute_id'],

                                'store_id' => 0,

                                'entity_id' => $entityId,

                                'value' =>
                                    date('Y-m-d H:i:s')
                            ];
                        }

                        if ($specialToDateAttribute !== null) {

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
                }

                /*
                |--------------------------------------------------------------------------
                | WEIGHT
                |--------------------------------------------------------------------------
                */

                if (
                    isset($product['weight']) &&
                    $weightAttribute !== null
                ) {

                    $newWeight =
                        ((float)$product['weight']) / 1000;

                    $attributeId =
                        (int)$weightAttribute['attribute_id'];

                    $current =
                        $existingDecimal[
                            $entityId
                        ][
                            $attributeId
                        ] ?? null;

                    if (
                        $current === null ||
                        (float)$current !== $newWeight
                    ) {

                        $decimalRows[] = [
                            'attribute_id' =>
                                $attributeId,

                            'store_id' => 0,

                            'entity_id' => $entityId,

                            'value' => $newWeight
                        ];
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | TAX CLASS
                |--------------------------------------------------------------------------
                */

                if (
                    !empty($product['vat']) &&
                    $taxClassAttribute !== null
                ) {

                    $taxClassId =
                        $this->getTaxClassIdByName(
                            (string)$product['vat']
                        );

                    if ($taxClassId) {

                        $intRows[] = [
                            'attribute_id' => (int)$taxClassAttribute['attribute_id'],
                            'store_id' => 0,
                            'entity_id' => $entityId,
                            'value' => $taxClassId
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

                if (
                    isset($product['manufacturer']) &&
                    $manufacturerAttribute !== null
                ) {

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
                | GEST TIPOLOGIA
                |--------------------------------------------------------------------------
                */

                if (!empty($product['macroGroup'])) {

                    $code = strtoupper(
                        trim(
                            (string)$product['macroGroup']
                        )
                    );

                    $optionId =
                        $gestTipologiaMap[$code]
                        ?? null;

                    if (
                        $gestTipologiaAttribute !== null &&
                        $optionId
                    ) {

                        $intRows[] = [
                            'attribute_id' =>
                                (int)$gestTipologiaAttribute['attribute_id'],

                            'store_id' => 0,

                            'entity_id' => $entityId,

                            'value' => $optionId
                        ];
                    }
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

            $connection
                ->commit();

            $this->logger->info(
                sprintf(
                    'Importati %d prodotti',
                    count($products)
                )
            );

            return $responses;
        } 
        catch (\Throwable $e) 
        {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK
            |--------------------------------------------------------------------------
            */

            $connection
                ->rollBack();

            $this->logger
                ->critical($e);

            throw $e;
        }
    }

    private function getTaxClassIdByName(string $value): ?int
    {
        $connection =
            $this->resource->getConnection();

        $table =
            $this->resource->getTableName(
                'tax_class'
            );

        $result = $connection->fetchOne(
            $connection->select()
                ->from(
                    $table,
                    ['class_id']
                )
                ->where('class_name = ?', $value)
                ->limit(1)
        );

        return $result
            ? (int)$result
            : null;
    }

    private function getRequiredAttribute(string $code): array
    {
        $attribute =
            $this->attributeRepository
                ->getAttribute($code);

        if ($attribute === null) {
            throw new \RuntimeException(
                sprintf(
                    'Attributo prodotto Magento non trovato: %s',
                    $code
                )
            );
        }

        return $attribute;
    }

    private function getOptionalAttribute(string $code): ?array
    {
        $attribute =
            $this->attributeRepository
                ->getAttribute($code);

        if ($attribute === null) {
            $this->logger->warning(
                sprintf(
                    'Attributo prodotto Magento opzionale non trovato: %s',
                    $code
                )
            );
        }

        return $attribute;
    }

    private function getAttributeOptionsMap(
        string $attributeCode
    ): array
    {
        $connection =
            $this->resource->getConnection();

        $attributeTable =
            $this->resource->getTableName(
                'eav_attribute'
            );

        $optionTable =
            $this->resource->getTableName(
                'eav_attribute_option'
            );

        $optionValueTable =
            $this->resource->getTableName(
                'eav_attribute_option_value'
            );

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['a' => $attributeTable],
                    []
                )
                ->join(
                    ['o' => $optionTable],
                    'a.attribute_id = o.attribute_id',
                    ['option_id']
                )
                ->join(
                    ['v' => $optionValueTable],
                    'o.option_id = v.option_id',
                    ['value']
                )
                ->where(
                    'a.attribute_code = ?',
                    $attributeCode
                )
        );

        $map = [];

        foreach ($rows as $row) {

            $map[
                strtoupper(
                    trim($row['value'])
                )
            ] = (int)$row['option_id'];
        }

        return $map;
    }
}
