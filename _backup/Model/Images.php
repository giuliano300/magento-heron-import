<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ImagesInterface;
use Heron\Bulk\Service\ProductImageService;
use Psr\Log\LoggerInterface;

class Images implements ImagesInterface
{
    private ProductImageService $productImageService;

    private LoggerInterface $logger;

    public function __construct(
        ProductImageService $productImageService,
        LoggerInterface $logger
    ) {
        $this->productImageService =
            $productImageService;

        $this->logger = $logger;
    }

    public function import(string $items)
    {
        try {

            $decoded =
                json_decode(
                    $items,
                    true
                );

            if (!is_array($decoded)) {

                throw new \Exception(
                    'JSON non valido'
                );
            }

            return $this
                ->productImageService
                ->import($decoded);

        } catch (\Throwable $e) {

            $this->logger->critical($e);

            return [
                [
                    'success' => false,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }
}