<?php

namespace Heron\Bulk\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class ProductImageService
{
    private ProductRepositoryInterface $productRepository;

    private DirectoryList $directoryList;

    private LoggerInterface $logger;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        DirectoryList $directoryList,
        LoggerInterface $logger
    ) {
        $this->productRepository =
            $productRepository;

        $this->directoryList =
            $directoryList;

        $this->logger = $logger;
    }

    public function import(array $items): array
    {
        $responses = [];

        $tmpDir =
            $this->directoryList->getPath('media') . '/tmp/heron';

        if (!is_dir($tmpDir)) {

            mkdir(
                $tmpDir,
                0777,
                true
            );
        }

        foreach ($items as $item) {


            $this->logger->info(
                'ITEM: ' . json_encode($item)
            );

            $sku =
                $item['sku'] ?? '';

            try {

                /*
                |--------------------------------------------------------------------------
                | SKU
                |--------------------------------------------------------------------------
                */

                if (empty($sku)) {

                    $responses[] = [
                        'sku' => '',
                        'success' => false,
                        'message' => 'SKU mancante'
                    ];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | IMAGES
                |--------------------------------------------------------------------------
                */

                if (
                    empty($item['images']) ||
                    !is_array($item['images'])
                ) {

                    $responses[] = [
                        'sku' => $sku,
                        'success' => false,
                        'message' => 'Nessuna immagine'
                    ];

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | PRODUCT
                |--------------------------------------------------------------------------
                */

                $product =
                    $this->productRepository
                        ->get($sku);

                /*
                |--------------------------------------------------------------------------
                | REMOVE OLD IMAGES
                |--------------------------------------------------------------------------
                */

                $mediaGalleryEntries =
                    $product->getMediaGalleryEntries();

                if (!empty($mediaGalleryEntries)) {

                    foreach (
                        $mediaGalleryEntries
                        as $entry
                    ) {

                        try {

                            $product->removeImage(
                                $entry->getFile()
                            );

                        } catch (\Throwable $e) {

                            $this->logger->error(
                                $e->getMessage()
                            );
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | IMPORT NEW IMAGES
                |--------------------------------------------------------------------------
                */

                $imported = 0;

                foreach ($item['images'] as $image) {

                    $this->logger->info(
                        'IMAGE: ' . json_encode([
                            'name' => $image['name'] ?? '',
                            'base64_len' =>
                                strlen($image['base64'] ?? '')
                        ])
                    );

                    try {

                        $base64 =
                            $image['base64'] ?? '';

                        if (empty($base64)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | FILE NAME
                        |--------------------------------------------------------------------------
                        */

                        $name =
                            $image['name']
                            ?? uniqid() . '.jpg';

                        /*
                        |--------------------------------------------------------------------------
                        | REMOVE BASE64 PREFIX
                        |--------------------------------------------------------------------------
                        */

                        if (
                            strpos(
                                $base64,
                                'base64,'
                            ) !== false
                        ) {

                            $parts =
                                explode(
                                    'base64,',
                                    $base64
                                );

                            $base64 =
                                end($parts);
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | TMP FILE
                        |--------------------------------------------------------------------------
                        */

                        $decoded = base64_decode(
                            $base64,
                            true
                        );

                        if ($decoded === false) {

                            throw new \Exception(
                                'Base64 non valido'
                            );
                        }


                        $tmpFile =
                            $tmpDir
                            . '/'
                            . uniqid()
                            . '-'
                            . $name;

                        file_put_contents(
                            $tmpFile,
                            $decoded
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | ADD IMAGE
                        |--------------------------------------------------------------------------
                        */

                        $roles =
                            $imported === 0
                                ? [
                                    'image',
                                    'small_image',
                                    'thumbnail'
                                ]
                                : null;

                        $product->addImageToMediaGallery(
                            $tmpFile,
                            $roles,
                            false,
                            false
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | DELETE TMP
                        |--------------------------------------------------------------------------
                        */

                        if (file_exists($tmpFile)) {

                            unlink($tmpFile);
                        }

                        $imported++;

                    } catch (\Throwable $e) {

                        $this->logger->error(
                            sprintf(
                                'Errore immagine SKU %s: %s',
                                $sku,
                                $e->getMessage()
                            )
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | SAVE PRODUCT
                |--------------------------------------------------------------------------
                */

                $this->productRepository
                    ->save($product);

                $responses[] = [
                    'sku' => $sku,
                    'success' => true,
                    'message' =>
                        $imported
                        . ' immagini importate'
                ];

            } catch (NoSuchEntityException $e) {

                $responses[] = [
                    'sku' => $sku,
                    'success' => false,
                    'message' => 'Prodotto non trovato'
                ];

            } catch (\Throwable $e) {

                $this->logger->critical($e);

                $responses[] = [
                    'sku' => $sku,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $responses;
    }
}