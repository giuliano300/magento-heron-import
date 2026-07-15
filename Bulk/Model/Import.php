<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ImportInterface;
use Heron\Bulk\Service\BulkImportService;
use Psr\Log\LoggerInterface;


class Import implements ImportInterface
{
    private BulkImportService $bulkImportService;
    private LoggerInterface $logger;

    public function __construct(
        BulkImportService $bulkImportService,
        LoggerInterface $logger
    ) {
        $this->bulkImportService = $bulkImportService;
        $this->logger = $logger;
    }

    public function import(string $products, string $batchId = '')
    {
        try {

            $decoded = json_decode(
                $products,
                true
            );

            if (!is_array($decoded)) {

                throw new \Exception(
                    'JSON non valido'
                );
            }

            $result =
                $this->bulkImportService
                    ->import($decoded, $batchId);


            return json_encode([
                'success' => true,
                'total' => count($result),
                'items' => $result
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
