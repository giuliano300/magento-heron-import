<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\StopBatchInterface;

class StopBatch implements StopBatchInterface
{
    public function execute(string $batchId): array
    {
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $batchId)) {
            return [
                'success' => false,
                'message' => 'Batch ID non valido'
            ];
        }

        $logDir = BP . '/var/import/images/logs';

        if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            return [
                'success' => false,
                'message' => 'Impossibile creare la directory dei batch'
            ];
        }

        $stopFile = $logDir . '/' . $batchId . '.stop';

        if (file_put_contents($stopFile, date('c'), LOCK_EX) === false) {
            return [
                'success' => false,
                'message' => 'Impossibile richiedere lo stop del batch'
            ];
        }

        return [
            'success' => true,
            'message' => 'Arresto batch richiesto',
            'batch_id' => $batchId
        ];
    }
}
