<?php

namespace Heron\Bulk\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Cache\TypeListInterface;

class ImageBulkDbImportService
{
    private ResourceConnection $resource;

    private DirectoryList $directoryList;

    private LoggerInterface $logger;
    
    private TypeListInterface $cacheTypeList;

    private MagentoCommandService $magentoCommandService;
    
    public function __construct(
        ResourceConnection $resource,
        DirectoryList $directoryList,
        LoggerInterface $logger,
        TypeListInterface $cacheTypeList,
        MagentoCommandService $magentoCommandService
    ) {
        $this->resource = $resource;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
        $this->cacheTypeList = $cacheTypeList;
        $this->magentoCommandService = $magentoCommandService;
    }

    public function import(string $batchId): array
    {
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $batchId)) {

            return [
                'success' => false,
                'message' => 'Batch ID non valido'
            ];
        }

        $this->logger->info(
            'IMAGE IMPORT START',
            [
                'batch_id' => $batchId
            ]
        );

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

        $galleryTable =
            $this->resource->getTableName(
                'catalog_product_entity_media_gallery'
            );

        $galleryValueTable =
            $this->resource->getTableName(
                'catalog_product_entity_media_gallery_value'
            );

        $galleryLinkTable =
            $this->resource->getTableName(
                'catalog_product_entity_media_gallery_value_to_entity'
            );

        $varcharTable =
            $this->resource->getTableName(
                'catalog_product_entity_varchar'
            );

        $eavTable =
            $this->resource->getTableName(
                'eav_attribute'
            );

        /*
        |--------------------------------------------------------------------------
        | ATTRIBUTE IDS
        |--------------------------------------------------------------------------
        */

        $attributes =
            $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $eavTable,
                        [
                            'attribute_code',
                            'attribute_id'
                        ]
                    )
                    ->where(
                        'attribute_code IN (?)',
                        [
                            'image',
                            'small_image',
                            'thumbnail',
                            'media_gallery'
                        ]
                    )
            );

        $this->logger->info(
            'ATTRIBUTES',
            $attributes
        );

        $missingAttributes =
            array_diff(
                [
                    'image',
                    'small_image',
                    'thumbnail',
                    'media_gallery'
                ],
                array_keys($attributes)
            );

        if (!empty($missingAttributes)) {

            $message =
                'Attributi immagini mancanti: '
                . implode(
                    ', ',
                    $missingAttributes
                );

            $this->logger->critical(
                $message
            );

            return [
                'success' => false,
                'message' => $message
            ];
        }

        $imageAttributeId =
            (int)$attributes['image'];

        $smallImageAttributeId =
            (int)$attributes['small_image'];

        $thumbnailAttributeId =
            (int)$attributes['thumbnail'];

        $mediaGalleryAttributeId =
            (int)$attributes['media_gallery'];

        /*
        |--------------------------------------------------------------------------
        | PATHS
        |--------------------------------------------------------------------------
        */

        $importDir =
            $this->directoryList
                ->getPath('var')
            . '/import/images';

        $zipFile =
            $importDir
            . '/'
            . $batchId
            . '.zip';

        $extractPath =
            $importDir
            . '/'
            . $batchId;

        $mediaRoot =
            $this->directoryList
                ->getPath('media')
            . '/catalog/product';

        $this->logger->info(
            'PATHS',
            [
                'zip' => $zipFile,
                'extract' => $extractPath,
                'media' => $mediaRoot
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | LOG FILES
        |--------------------------------------------------------------------------
        */

        $logDir =
            $importDir
            . '/logs';

        $statusFile =
            $logDir
            . '/'
            . $batchId
            . '.json';

        $insertedFile =
            $logDir
            . '/'
            . $batchId
            . '.inserted.log';

        $errorFile =
            $logDir
            . '/'
            . $batchId
            . '.errors.log';

        $stopFile =
            $logDir
            . '/'
            . $batchId
            . '.stop';

        if (!is_dir($logDir)) {

            mkdir(
                $logDir,
                0777,
                true
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ZIP EXISTS
        |--------------------------------------------------------------------------
        */

        if (!file_exists($zipFile)) {

            $this->logger->error(
                'ZIP NOT FOUND',
                [
                    'zip' => $zipFile
                ]
            );

            return [
                'success' => false,
                'message' => 'ZIP non trovato'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | EXTRACT DIR
        |--------------------------------------------------------------------------
        */

        if (!is_dir($extractPath)) {

            mkdir(
                $extractPath,
                0777,
                true
            );
        }

        /*
        |--------------------------------------------------------------------------
        | UNZIP
        |--------------------------------------------------------------------------
        */

        $zip =
            new \ZipArchive();

        $open =
            $zip->open($zipFile);

        if ($open !== true) {

            $this->logger->critical(
                'ZIP OPEN ERROR',
                [
                    'code' => $open
                ]
            );

            return [
                'success' => false,
                'message' => 'Errore apertura ZIP'
            ];
        }

        try {

            $this->extractZipSafely(
                $zip,
                $extractPath
            );

        } finally {

            $zip->close();
        }

        $this->logger->info(
            'ZIP EXTRACTED'
        );

        /*
        |--------------------------------------------------------------------------
        | FILES
        |--------------------------------------------------------------------------
        */

        $files =
            scandir($extractPath);

        $parsed = [];

        foreach ($files as $file) {

            if (
                $file === '.' ||
                $file === '..'
            ) {
                continue;
            }

            if (!is_file($extractPath . '/' . $file)) {
                continue;
            }

            if (
                !preg_match(
                    '/^(.*?)_(\d+)\.(jpg|jpeg|png|webp)$/i',
                    $file,
                    $matches
                )
            ) {

                $this->logger->warning(
                    'INVALID FILE NAME',
                    [
                        'file' => $file
                    ]
                );

                continue;
            }

            $parsed[] = [
                'sku' => $matches[1],
                'position' => (int)$matches[2],
                'file' => $file
            ];
        }

        $this->logger->info(
            'FILES PARSED',
            [
                'count' => count($parsed)
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | PRODUCTS
        |--------------------------------------------------------------------------
        */

        $skus =
            array_unique(
                array_column(
                    $parsed,
                    'sku'
                )
            );

        $products =
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

        $this->logger->info(
            'PRODUCTS FOUND',
            [
                'count' => count($products)
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | TOTAL
        |--------------------------------------------------------------------------
        */

        $total =
            count($parsed);

        $processed = 0;

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        file_put_contents(
            $statusFile,
            json_encode(
                [
                    'running' => true,
                    'stopped' => false,
                    'processed' => 0,
                    'total' => $total,
                    'percent' => 0,
                    'updated_at' =>
                        date(
                            'Y-m-d H:i:s'
                        )
                ],
                JSON_PRETTY_PRINT
            )
        );

        /*
        |--------------------------------------------------------------------------
        | CLEANED
        |--------------------------------------------------------------------------
        */

        $cleaned = [];
        $stopped = false;

        try {

            foreach ($parsed as $row) {

                // Lo stop viene applicato tra due immagini, mai nel mezzo delle
                // scritture DB di una singola immagine, per evitare dati parziali.
                clearstatcache(true, $stopFile);

                if (file_exists($stopFile)) {
                    $stopped = true;

                    $this->logger->warning(
                        'IMAGE IMPORT STOP REQUESTED',
                        [
                            'batch_id' => $batchId,
                            'processed' => $processed,
                            'total' => $total
                        ]
                    );

                    break;
                }

                try {

                    $processed++;

                    $this->logger->info(
                        'START IMAGE',
                        [
                            'sku' => $row['sku'],
                            'file' => $row['file'],
                            'position' => $row['position']
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | PRODUCT EXISTS
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !isset(
                            $products[
                                $row['sku']
                            ]
                        )
                    ) {

                        $this->logger->error(
                            'PRODUCT NOT FOUND',
                            [
                                'sku' => $row['sku']
                            ]
                        );

                        continue;
                    }

                    $entityId =
                        (int)$products[
                            $row['sku']
                        ][
                            'entity_id'
                        ];

                    $this->logger->info(
                        'ENTITY FOUND',
                        [
                            'entity_id' => $entityId
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DELETE OLD IMAGES
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !isset(
                            $cleaned[$entityId]
                        )
                    ) {

                        $this->logger->info(
                            'DELETE OLD IMAGES',
                            [
                                'entity_id' => $entityId
                            ]
                        );

                        $oldImages =
                            $connection->fetchAll(
                                $connection->select()
                                    ->from(
                                        ['mg' => $galleryTable],
                                        [
                                            'value_id',
                                            'value'
                                        ]
                                    )
                                    ->join(
                                        ['link' => $galleryLinkTable],
                                        'mg.value_id = link.value_id',
                                        []
                                    )
                                    ->where(
                                        'link.entity_id = ?',
                                        $entityId
                                    )
                            );

                        $valueIds =
                            array_column(
                                $oldImages,
                                'value_id'
                            );

                        if (!empty($valueIds)) {

                            $connection->delete(
                                $galleryValueTable,
                                [
                                    'value_id IN (?)' =>
                                        $valueIds
                                ]
                            );

                            $connection->delete(
                                $galleryLinkTable,
                                [
                                    'value_id IN (?)' =>
                                        $valueIds
                                ]
                            );

                            $connection->delete(
                                $galleryTable,
                                [
                                    'value_id IN (?)' =>
                                        $valueIds
                                ]
                            );
                        }

                        $connection->delete(
                            $varcharTable,
                            [
                                'entity_id = ?' =>
                                    $entityId,

                                'attribute_id IN (?)' => [
                                    $imageAttributeId,
                                    $smallImageAttributeId,
                                    $thumbnailAttributeId
                                ]
                            ]
                        );

                        foreach ($oldImages as $img) {

                            $oldFile =
                                $mediaRoot
                                . $img['value'];

                            if (
                                file_exists($oldFile)
                            ) {

                                @unlink($oldFile);
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | DELETE RESIZED CACHE
                            |--------------------------------------------------------------------------
                            */

                            $cacheRoot =
                                $mediaRoot
                                . '/cache';

                            if (is_dir($cacheRoot)) {
                                $this->deleteImageFromCache(
                                    $cacheRoot,
                                    basename($img['value'])
                                );
                            }
                        }

                        $cleaned[$entityId] = true;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | PATHS
                    |--------------------------------------------------------------------------
                    */

                    $relative =
                        $this->getMagentoImagePath(
                            $row['file']
                        );

                    $source =
                        $extractPath
                        . '/'
                        . $row['file'];

                    $destination =
                        $mediaRoot
                        . $relative;

                    $destinationDir =
                        dirname($destination);

                    $this->logger->info(
                        'COPY PATHS',
                        [
                            'source' => $source,
                            'destination' => $destination,
                            'relative' => $relative
                        ]
                    );

                    if (
                        !is_dir(
                            $destinationDir
                        )
                    ) {

                        mkdir(
                            $destinationDir,
                            0777,
                            true
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | COPY FILE
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !copy(
                            $source,
                            $destination
                        )
                    ) {

                        throw new \Exception(
                            'Errore copia file'
                        );
                    }

                    if (
                        !file_exists(
                            $destination
                        )
                    ) {

                        throw new \Exception(
                            'File non copiato'
                        );
                    }

                    $this->logger->info(
                        'FILE COPIED'
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT MEDIA GALLERY
                    |--------------------------------------------------------------------------
                    */

                    $galleryData = [
                        'attribute_id' =>
                            $mediaGalleryAttributeId,

                        'value' =>
                            $relative,

                        'media_type' =>
                            'image'
                    ];

                    $this->logger->info(
                        'INSERT GALLERY',
                        $galleryData
                    );

                    $connection->insert(
                        $galleryTable,
                        $galleryData
                    );

                    $valueId =
                        (int)$connection
                            ->lastInsertId(
                                $galleryTable
                            );

                    $this->logger->info(
                        'VALUE ID CREATED',
                        [
                            'value_id' => $valueId
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT VALUE
                    |--------------------------------------------------------------------------
                    */

                    $galleryValueData = [
                        'value_id' => $valueId,

                        'entity_id' => $entityId,

                        'store_id' => 0,

                        'label' => null,

                        'position' => $row['position'],

                        'disabled' => 0
                    ];

                    $this->logger->info(
                        'INSERT GALLERY VALUE',
                        [
                            'value_id' => $valueId,
                            'entity_id' => $entityId
                        ]
                    );

                    $connection->insert(
                        $galleryValueTable,
                        $galleryValueData
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT LINK
                    |--------------------------------------------------------------------------
                    */

                    $galleryLinkData = [
                        'value_id' => $valueId,
                        'entity_id' => $entityId
                    ];

                    $this->logger->info(
                        'INSERT GALLERY LINK',
                        $galleryLinkData
                    );

                    $connection->insert(
                        $galleryLinkTable,
                        $galleryLinkData
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | MAIN IMAGE
                    |--------------------------------------------------------------------------
                    */

                    if ($row['position'] === 1) {

                        $varcharData = [
                            [
                                'attribute_id' =>
                                    $imageAttributeId,

                                'store_id' => 0,

                                'entity_id' => $entityId,

                                'value' => $relative
                            ],
                            [
                                'attribute_id' =>
                                    $smallImageAttributeId,

                                'store_id' => 0,

                                'entity_id' => $entityId,

                                'value' => $relative
                            ],
                            [
                                'attribute_id' =>
                                    $thumbnailAttributeId,

                                'store_id' => 0,

                                'entity_id' => $entityId,

                                'value' => $relative
                            ]
                        ];

                        $this->logger->info(
                            'INSERT MAIN IMAGE',
                            $varcharData
                        );

                        $connection->insertOnDuplicate(
                            $varcharTable,
                            $varcharData,
                            ['value']
                        );

                         /*
                        |--------------------------------------------------------------------------
                        | FORCE PRODUCT UPDATE
                        |--------------------------------------------------------------------------
                        */

                        $connection->update(
                            $entityTable,
                            [
                                'updated_at' =>
                                    date(
                                        'Y-m-d H:i:s'
                                    )
                            ],
                            [
                                'entity_id = ?' =>
                                    $entityId
                            ]
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | APPEND INSERTED
                    |--------------------------------------------------------------------------
                    */

                    file_put_contents(
                        $insertedFile,
                        $row['sku']
                        . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );

                    $this->logger->info(
                        'IMAGE IMPORTED',
                        [
                            'sku' => $row['sku']
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE STATUS
                    |--------------------------------------------------------------------------
                    */

                    if ($processed % 100 === 0) {

                        file_put_contents(
                            $statusFile,
                            json_encode(
                                [
                                    'running' => true,

                                    'stopped' => false,

                                    'processed' => $processed,

                                    'total' => $total,

                                    'percent' =>
                                        round(
                                            (
                                                $processed
                                                / $total
                                            ) * 100,
                                            2
                                        ),

                                    'updated_at' =>
                                        date(
                                            'Y-m-d H:i:s'
                                        )
                                ],
                                JSON_PRETTY_PRINT
                            )
                        );
                    }
                }
                catch (\Throwable $e) {

                    $this->logger->critical(
                        'IMAGE IMPORT ERROR',
                        [
                            'sku' => $row['sku'],
                            'file' => $row['file'],
                            'message' => $e->getMessage(),
                            'trace' =>
                                $e->getTraceAsString()
                        ]
                    );

                    file_put_contents(
                        $errorFile,
                        json_encode([
                            'sku' => $row['sku'],
                            'message' => $e->getMessage()
                        ]) . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | FINAL STATUS
            |--------------------------------------------------------------------------
            */

            file_put_contents(
                $statusFile,
                json_encode(
                    [
                        'running' => false,

                        'stopped' => $stopped,

                        'processed' => $processed,

                        'total' => $total,

                        'percent' => $total > 0
                            ? round(($processed / $total) * 100, 2)
                            : 0,

                        'updated_at' =>
                            date(
                                'Y-m-d H:i:s'
                            )
                    ],
                    JSON_PRETTY_PRINT
                )
            );

            $this->logger->info(
                $stopped
                    ? 'IMAGE IMPORT STOPPED'
                    : 'IMAGE IMPORT COMPLETED',
                [
                    'processed' => $processed,
                    'total' => $total
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | INVALIDATE INDEXER
            |--------------------------------------------------------------------------
            */

            $indexerStateTable =
                $this->resource->getTableName(
                    'indexer_state'
                );

            $connection->update(
                $indexerStateTable,
                [
                    'status' => 'invalid'
                ],
                [
                    'indexer_id = ?' =>
                        'catalog_product_attribute'
                ]
            );

            if ($stopped) {
                @unlink($stopFile);

                return [
                    'success' => true,
                    'stopped' => true,
                    'processed' => $processed,
                    'total' => $total
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | REINDEX / IMAGE RESIZE / CACHE
            |--------------------------------------------------------------------------
            */

            $this->magentoCommandService
                ->run([
                    'indexer:reindex',
                    'catalog_product_attribute'
                ]);

            $this->clearCatalogImageCache();

            $this->magentoCommandService
                ->run([
                    'catalog:images:resize'
                ]);

            $this->magentoCommandService
                ->run([
                    'cache:flush'
                ]);

            return [
                'success' => true,
                'processed' => $processed,
                'total' => $total
            ];
        }
        catch (\Throwable $e) {

            $this->logger->critical(
                'GLOBAL IMPORT ERROR',
                [
                    'message' => $e->getMessage(),
                    'trace' =>
                        $e->getTraceAsString()
                ]
            );

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function deleteImageFromCache(
        string $cacheRoot,
        string $fileName
    ): void {

        $iterator =
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $cacheRoot,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

        foreach ($iterator as $file) {

            if (
                $file->isFile() &&
                $file->getFilename() === $fileName
            ) {
                @unlink(
                    $file->getPathname()
                );
            }
        }
    }

    private function extractZipSafely(
        \ZipArchive $zip,
        string $extractPath
    ): void {

        $basePath =
            realpath($extractPath);

        if ($basePath === false) {

            throw new \RuntimeException(
                'Cartella estrazione non valida'
            );
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {

            $entryName =
                $zip->getNameIndex($i);

            if (
                $entryName === false ||
                substr($entryName, -1) === '/'
            ) {
                continue;
            }

            $normalizedName =
                str_replace(
                    '\\',
                    '/',
                    $entryName
                );

            if (
                strpos($normalizedName, '../') !== false ||
                strpos($normalizedName, '/') === 0 ||
                preg_match('/^[a-zA-Z]:\//', $normalizedName)
            ) {

                throw new \RuntimeException(
                    'ZIP contiene percorso non valido: '
                    . $entryName
                );
            }

            $targetFile =
                $basePath
                . DIRECTORY_SEPARATOR
                . basename($normalizedName);

            $stream =
                $zip->getStream($entryName);

            if ($stream === false) {

                throw new \RuntimeException(
                    'Errore lettura file ZIP: '
                    . $entryName
                );
            }

            $target =
                fopen(
                    $targetFile,
                    'wb'
                );

            if ($target === false) {

                fclose($stream);

                throw new \RuntimeException(
                    'Errore scrittura file ZIP: '
                    . $entryName
                );
            }

            stream_copy_to_stream(
                $stream,
                $target
            );

            fclose($stream);
            fclose($target);
        }
    }

    private function getMagentoImagePath(
        string $file
    ): string {

        $hash =
            md5($file);

        return sprintf(
            '/%s/%s/%s',
            $hash[0],
            $hash[1],
            $file
        );
    }

    private function clearCatalogImageCache(): void
    {
        $cacheRoot =
            $this->directoryList
                ->getPath('media')
            . '/catalog/product/cache';

        if (!is_dir($cacheRoot)) {
            return;
        }

        try {

            $iterator =
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $cacheRoot,
                        \FilesystemIterator::SKIP_DOTS
                    ),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

            foreach ($iterator as $file) {

                if ($file->isDir()) {

                    @rmdir(
                        $file->getPathname()
                    );

                    continue;
                }

                @unlink(
                    $file->getPathname()
                );
            }

            $this->logger->info(
                '[IMAGE CACHE CLEARED] ' . $cacheRoot
            );

        } catch (\Throwable $e) {

            $this->logger->warning(
                '[IMAGE CACHE CLEAR ERROR] '
                . $e->getMessage()
            );
        }
    }
}
