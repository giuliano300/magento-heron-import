<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ReindexStatusInterface;

class ReindexStatus implements ReindexStatusInterface
{
    public function execute(string $batchId)
    {
        try {

            $file =
                BP .
                '/var/log/heron/reindex-status-' .
                preg_replace(
                    '/[^a-zA-Z0-9\-_]/',
                    '',
                    $batchId
                ) .
                '.json';

            if (!file_exists($file)) {

                return '{"running":false,"exists":false}';
            }

            $content = file_get_contents($file);

            if (
                $content === false ||
                empty($content)
            ) {

                return '{"running":false,"error":true}';
            }

            return $content;

        } catch (\Throwable $e) {

            return json_encode([
                'running' => false,
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }
}