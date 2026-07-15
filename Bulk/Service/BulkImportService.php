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

    public function import(array $products, string $batchId = ''): array
    {
        if (empty($products)) {
            return [];
        }

        $responses = [];

        $connection = $this->resource->getConnection();
        
        try 
        {
            $this->throwIfBatchStopped($batchId);

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

            foreach ($products as &$product) {

                $this->throwIfBatchStopped($batchId);

                if (isset($product['sku'])) {

                    $product['sku'] = trim(
                        (string)$product['sku']
                    );
                }
            }

            unset($product);

            $validSkus = [];
            $skuOccurrences = [];

            foreach ($products as $product) {

                $this->throwIfBatchStopped($batchId);

                if (
                    !isset($product['sku']) ||
                    $product['sku'] === '' ||
                    preg_match('/\s/', $product['sku'])
                ) {
                    continue;
                }

                $skuKey = strtoupper($product['sku']);

                $validSkus[$product['sku']] =
                    $product['sku'];

                $skuOccurrences[$skuKey] =
                    ($skuOccurrences[$skuKey] ?? 0) + 1;
            }

            $duplicateSkuKeys = array_filter(
                $skuOccurrences,
                static fn($count) => $count > 1
            );

            $skus = array_values($validSkus);

            $existingProducts = [];

            if (!empty($skus)) {

                $existingProducts = $connection->fetchAssoc(
                    $connection->select()
                        ->from(
                            $entityTable,
                            [
                                'sku',
                                'entity_id',
                                'attribute_set_id',
                                'type_id'
                            ]
                        )
                        ->where('sku IN (?)', $skus)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ENTITY INSERT
            |--------------------------------------------------------------------------
            */

            $entityRows = [];
            $changedSkus = [];
            $responseIndexesBySku = [];

            foreach ($products as $product) {

                $this->throwIfBatchStopped($batchId);

                $response = new ImportResponse();

                $response->sku =
                    $product['sku'] ?? '';

                try {

                    if (
                        !isset($product['sku']) ||
                        $product['sku'] === ''
                    ) {

                        $response->success = false;

                        $response->insertType =
                            ImportResponse::ERROR;

                        $response->message =
                            'SKU mancante';

                        $responses[] =
                            $response->toArray();

                        continue;
                    }

                    if (preg_match('/\s/', $product['sku'])) {

                        $response->success = false;

                        $response->insertType =
                            ImportResponse::ERROR;

                        $response->message =
                            'SKU non valido: contiene spazi';

                        $responses[] =
                            $response->toArray();

                        continue;
                    }

                    if (
                        isset(
                            $duplicateSkuKeys[
                                strtoupper($product['sku'])
                            ]
                        )
                    ) {

                        $response->success = false;

                        $response->insertType =
                            ImportResponse::ERROR;

                        $response->message =
                            'SKU duplicato nel payload';

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

                    $attributeSetId =
                        (int)($product['attribute_set_id'] ?? 4);

                    $typeId =
                        (string)($product['type_id'] ?? 'simple');

                    $entityChanged = false;

                    if (
                        !$exists ||
                        (int)$existingProducts[
                            $product['sku']
                        ][
                            'attribute_set_id'
                        ] !== $attributeSetId ||
                        (string)$existingProducts[
                            $product['sku']
                        ][
                            'type_id'
                        ] !== $typeId
                    ) {

                        $entityChanged = true;

                        $entityRows[] = [

                            'attribute_set_id' =>
                                $attributeSetId,

                            'type_id' =>
                                $typeId,

                            'sku' =>
                                $product['sku'],

                            'has_options' => 0,

                            'required_options' => 0,

                            'created_at' =>
                                date('Y-m-d H:i:s'),

                            'updated_at' =>
                                date('Y-m-d H:i:s')
                        ];
                    }

                    if (
                        !$exists ||
                        $entityChanged
                    ) {

                        $changedSkus[
                            $product['sku']
                        ] = true;
                    }

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

                if (
                    $response->success &&
                    $response->insertType === ImportResponse::UPDATE
                ) {

                    $responseIndexesBySku[
                        $product['sku']
                    ] = count($responses) - 1;
                }
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

            $entities = [];

            if (!empty($skus)) {

                $entityRows =
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

                $entities = $entityRows;
            }

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
            $existingVarchar = [];
            $existingText = [];
            $existingInt = [];
            $existingDatetime = [];
            $existingStock = [];
            $existingWebsites = [];
            $existingCategories = [];

            if (!empty($entityIds)) {

                $existingDecimal =
                    $this->getExistingAttributeValues(
                        $decimalTable,
                        $entityIds
                    );

                $existingVarchar =
                    $this->getExistingAttributeValues(
                        $varcharTable,
                        $entityIds
                    );

                $existingText =
                    $this->getExistingAttributeValues(
                        $textTable,
                        $entityIds
                    );

                $existingInt =
                    $this->getExistingAttributeValues(
                        $intTable,
                        $entityIds
                    );

                $existingDatetime =
                    $this->getExistingAttributeValues(
                        $datetimeTable,
                        $entityIds
                    );

                $stockRowsExisting = $connection->fetchAll(
                    $connection->select()
                        ->from(
                            $stockTable,
                            [
                                'product_id',
                                'qty',
                                'is_in_stock',
                                'manage_stock',
                                'use_config_manage_stock'
                            ]
                        )
                        ->where(
                            'product_id IN (?)',
                            $entityIds
                        )
                );

                foreach ($stockRowsExisting as $row) {

                    $existingStock[
                        (int)$row['product_id']
                    ] = $row;
                }

                $websiteRowsExisting = $connection->fetchAll(
                    $connection->select()
                        ->from(
                            $websiteTable,
                            [
                                'product_id',
                                'website_id'
                            ]
                        )
                        ->where(
                            'product_id IN (?)',
                            $entityIds
                        )
                );

                foreach ($websiteRowsExisting as $row) {

                    $existingWebsites[
                        (int)$row['product_id']
                    ][
                        (int)$row['website_id']
                    ] = true;
                }

                $categoryRowsExisting = $connection->fetchAll(
                    $connection->select()
                        ->from(
                            $categoryTable,
                            [
                                'product_id',
                                'category_id'
                            ]
                        )
                        ->where(
                            'product_id IN (?)',
                            $entityIds
                        )
                );

                foreach ($categoryRowsExisting as $row) {

                    $existingCategories[
                        (int)$row['product_id']
                    ][
                        (int)$row['category_id']
                    ] = true;
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
                    !isset($product['sku']) ||
                    $product['sku'] === '' ||
                    preg_match('/\s/', $product['sku']) ||
                    isset(
                        $duplicateSkuKeys[
                            strtoupper($product['sku'])
                        ]
                    ) ||
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

                    if ($this->addAttributeRowIfChanged(
                        $varcharRows,
                        $existingVarchar,
                        $entityId,
                        (int)$nameAttribute['attribute_id'],
                        (string)$product['name']
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }
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

                    if ($this->addAttributeRowIfChanged(
                        $varcharRows,
                        $existingVarchar,
                        $entityId,
                        (int)$urlKeyAttribute['attribute_id'],
                        $urlKey
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }
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

                    if ($this->addAttributeRowIfChanged(
                        $varcharRows,
                        $existingVarchar,
                        $entityId,
                        (int)$supplierAttribute['attribute_id'],
                        (string)$product['supplier']
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }
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

                    if ($this->addAttributeRowIfChanged(
                        $textRows,
                        $existingText,
                        $entityId,
                        (int)$descriptionAttribute['attribute_id'],
                        $description
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }

                    if ($siteDescriptionAttribute !== null) {

                        if ($this->addAttributeRowIfChanged(
                            $textRows,
                            $existingText,
                            $entityId,
                            (int)$siteDescriptionAttribute['attribute_id'],
                            $description
                        )) {

                            $changedSkus[$product['sku']] = true;
                        }
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

                    if ($this->addAttributeRowIfChanged(
                        $textRows,
                        $existingText,
                        $entityId,
                        (int)$shortDescriptionAttribute['attribute_id'],
                        $short
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }

                    if ($siteShortDescriptionAttribute !== null) {

                        if ($this->addAttributeRowIfChanged(
                            $textRows,
                            $existingText,
                            $entityId,
                            (int)$siteShortDescriptionAttribute['attribute_id'],
                            $short
                        )) {

                            $changedSkus[$product['sku']] = true;
                        }
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

                    if ($this->addDecimalRowIfChanged(
                        $decimalRows,
                        $existingDecimal,
                        $entityId,
                        $attributeId,
                        $newPrice
                    )) {

                        $changedSkus[$product['sku']] = true;
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

                    if (
                        $this->addDecimalRowIfChanged(
                            $decimalRows,
                            $existingDecimal,
                            $entityId,
                            $attributeId,
                            $newSpecial
                        )
                    ) {

                        $changedSkus[$product['sku']] = true;

                        if ($specialFromDateAttribute !== null) {

                            $this->addAttributeRowIfChanged(
                                $datetimeRows,
                                $existingDatetime,
                                $entityId,
                                (int)$specialFromDateAttribute['attribute_id'],
                                date('Y-m-d H:i:s')
                            );
                        }

                        if ($specialToDateAttribute !== null) {

                            $this->addAttributeRowIfChanged(
                                $datetimeRows,
                                $existingDatetime,
                                $entityId,
                                (int)$specialToDateAttribute['attribute_id'],
                                '2035-12-31 23:59:59'
                            );
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

                    if ($this->addDecimalRowIfChanged(
                        $decimalRows,
                        $existingDecimal,
                        $entityId,
                        $attributeId,
                        $newWeight
                    )) {

                        $changedSkus[$product['sku']] = true;
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

                        if ($this->addAttributeRowIfChanged(
                            $intRows,
                            $existingInt,
                            $entityId,
                            (int)$taxClassAttribute['attribute_id'],
                            (int)$taxClassId
                        )) {

                            $changedSkus[$product['sku']] = true;
                        }
                    }
                }           

                /*
                |--------------------------------------------------------------------------
                | STATUS
                |--------------------------------------------------------------------------
                */

                if ($this->addAttributeRowIfChanged(
                    $intRows,
                    $existingInt,
                    $entityId,
                    (int)$statusAttribute['attribute_id'],
                    $this->getMagentoStatus($product)
                )) {

                    $changedSkus[$product['sku']] = true;
                }

                /*
                |--------------------------------------------------------------------------
                | VISIBILITY
                |--------------------------------------------------------------------------
                */

                if ($this->addAttributeRowIfChanged(
                    $intRows,
                    $existingInt,
                    $entityId,
                    (int)$visibilityAttribute['attribute_id'],
                    (int)($product['visibility'] ?? 4)
                )) {

                    $changedSkus[$product['sku']] = true;
                }

                /*
                |--------------------------------------------------------------------------
                | MANUFACTURER
                |--------------------------------------------------------------------------
                */

                if (
                    isset($product['manufacturer']) &&
                    $manufacturerAttribute !== null
                ) {

                    if ($this->addAttributeRowIfChanged(
                        $intRows,
                        $existingInt,
                        $entityId,
                        (int)$manufacturerAttribute['attribute_id'],
                        (int)$product['manufacturer']
                    )) {

                        $changedSkus[$product['sku']] = true;
                    }
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

                        if ($this->addAttributeRowIfChanged(
                            $intRows,
                            $existingInt,
                            $entityId,
                            (int)$gestTipologiaAttribute['attribute_id'],
                            (int)$optionId
                        )) {

                            $changedSkus[$product['sku']] = true;
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | STOCK
                |--------------------------------------------------------------------------
                */

                $qty =
                    (float)($product['qty'] ?? 0);

                $stockRow = [
                    'product_id' => $entityId,
                    'stock_id' => 1,
                    'qty' => $qty,
                    'is_in_stock' =>
                        $qty > 0 ? 1 : 0,
                    'manage_stock' => 1,
                    'use_config_manage_stock' => 1
                ];

                if (
                    $this->stockRowChanged(
                        $existingStock,
                        $entityId,
                        $stockRow
                    )
                ) {

                    $stockRows[] = $stockRow;
                    $changedSkus[$product['sku']] = true;
                }

                /*
                |--------------------------------------------------------------------------
                | WEBSITES
                |--------------------------------------------------------------------------
                */

                if (!empty($product['website_ids'])) {

                    foreach (
                        array_unique($product['website_ids'])
                        as $websiteId
                    ) {

                        $websiteId = (int)$websiteId;

                        if (
                            isset(
                                $existingWebsites[
                                    $entityId
                                ][
                                    $websiteId
                                ]
                            )
                        ) {
                            continue;
                        }

                        $websiteRows[] = [
                            'product_id' => $entityId,
                            'website_id' => $websiteId
                        ];

                        $changedSkus[$product['sku']] = true;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CATEGORIES
                |--------------------------------------------------------------------------
                */

                if (!empty($product['category_ids'])) {

                    foreach (
                        array_unique($product['category_ids'])
                        as $categoryId
                    ) {

                        $categoryId = (int)$categoryId;

                        if (
                            isset(
                                $existingCategories[
                                    $entityId
                                ][
                                    $categoryId
                                ]
                            )
                        ) {
                            continue;
                        }

                        $categoryRows[] = [
                            'category_id' => $categoryId,
                            'product_id' => $entityId,
                            'position' => 0
                        ];

                        $changedSkus[$product['sku']] = true;
                    }
                }
            }

            foreach ($responseIndexesBySku as $sku => $responseIndex) {

                if (isset($changedSkus[$sku])) {
                    continue;
                }

                $responses[$responseIndex]['insertType'] =
                    ImportResponse::NONE;

                $responses[$responseIndex]['message'] =
                    'Prodotto invariato';
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

            // Ultimo controllo dentro la transazione: uno stop arrivato durante
            // le scritture causa rollback invece di confermare un chunk parziale.
            $this->throwIfBatchStopped($batchId);

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

    private function throwIfBatchStopped(string $batchId): void
    {
        if ($batchId === '') {
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $batchId)) {
            throw new \InvalidArgumentException('Batch ID non valido');
        }

        $stopFile = BP
            . '/var/import/images/logs/'
            . $batchId
            . '.stop';

        clearstatcache(true, $stopFile);

        if (file_exists($stopFile)) {
            throw new \RuntimeException(
                'Batch arrestato su richiesta: ' . $batchId
            );
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

    private function getMagentoStatus(array $product): int
    {
        foreach ([
            'status',
            'attivo',
            'active',
            'is_active'
        ] as $field) {

            if (array_key_exists($field, $product)) {
                return $this->normalizeStatusValue(
                    $product[$field]
                );
            }
        }

        return 1;
    }

    private function normalizeStatusValue($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 2;
        }

        if (is_numeric($value)) {

            $numericValue = (int)$value;

            if ($numericValue === 2) {
                return 2;
            }

            return $numericValue === 0
                ? 2
                : 1;
        }

        $normalized = strtolower(
            trim((string)$value)
        );

        if (
            in_array(
                $normalized,
                [
                    '0',
                    '2',
                    'false',
                    'no',
                    'n',
                    'non attivo',
                    'disattivo',
                    'disabled',
                    'disable'
                ],
                true
            )
        ) {
            return 2;
        }

        return 1;
    }

    private function getExistingAttributeValues(
        string $table,
        array $entityIds
    ): array
    {
        $connection =
            $this->resource->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $table,
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

        $values = [];

        foreach ($rows as $row) {

            $values[
                (int)$row['entity_id']
            ][
                (int)$row['attribute_id']
            ] = $row['value'];
        }

        return $values;
    }

    private function addAttributeRowIfChanged(
        array &$rows,
        array $existingValues,
        int $entityId,
        int $attributeId,
        $value
    ): bool
    {
        $current =
            $existingValues[
                $entityId
            ][
                $attributeId
            ] ?? null;

        if (
            $current !== null &&
            (string)$current === (string)$value
        ) {
            return false;
        }

        $rows[] = [
            'attribute_id' => $attributeId,
            'store_id' => 0,
            'entity_id' => $entityId,
            'value' => $value
        ];

        return true;
    }

    private function addDecimalRowIfChanged(
        array &$rows,
        array $existingValues,
        int $entityId,
        int $attributeId,
        float $value
    ): bool
    {
        $current =
            $existingValues[
                $entityId
            ][
                $attributeId
            ] ?? null;

        if (
            $current !== null &&
            (float)$current === $value
        ) {
            return false;
        }

        $rows[] = [
            'attribute_id' => $attributeId,
            'store_id' => 0,
            'entity_id' => $entityId,
            'value' => $value
        ];

        return true;
    }

    private function stockRowChanged(
        array $existingStock,
        int $entityId,
        array $stockRow
    ): bool
    {
        $current =
            $existingStock[$entityId]
            ?? null;

        if ($current === null) {
            return true;
        }

        return
            (float)$current['qty'] !== (float)$stockRow['qty'] ||
            (int)$current['is_in_stock'] !== (int)$stockRow['is_in_stock'] ||
            (int)$current['manage_stock'] !== (int)$stockRow['manage_stock'] ||
            (int)$current['use_config_manage_stock'] !==
                (int)$stockRow['use_config_manage_stock'];
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
