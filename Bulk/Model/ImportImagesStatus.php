<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ImportImagesStatusInterface;

class ImportImagesStatus
    implements ImportImagesStatusInterface
{
    public function execute(
        string $batchId
    ): string {

        try {

            /*
            |--------------------------------------------------------------------------
            | LOG DIR
            |--------------------------------------------------------------------------
            */

            $logDir =
                BP
                . '/var/import/images/logs';

            /*
            |--------------------------------------------------------------------------
            | FILES
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | STATUS EXISTS
            |--------------------------------------------------------------------------
            */

            if (!file_exists($statusFile)) {

                return json_encode([
                    'running' => false,
                    'exists' => false
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            $statusContent =
                file_get_contents(
                    $statusFile
                );

            if (
                empty($statusContent)
            ) {

                return json_encode([
                    'running' => false,
                    'error' => true
                ]);
            }

            $status =
                json_decode(
                    $statusContent,
                    true
                );

            /*
            |--------------------------------------------------------------------------
            | INSERTED
            |--------------------------------------------------------------------------
            */

            $inserted = [];

            if (
                file_exists(
                    $insertedFile
                )
            ) {

                $inserted =
                    file(
                        $insertedFile,
                        FILE_IGNORE_NEW_LINES |
                        FILE_SKIP_EMPTY_LINES
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | ERRORS
            |--------------------------------------------------------------------------
            */

            $errors = [];

            if (
                file_exists(
                    $errorFile
                )
            ) {

                $lines =
                    file(
                        $errorFile,
                        FILE_IGNORE_NEW_LINES |
                        FILE_SKIP_EMPTY_LINES
                    );

                foreach ($lines as $line) {

                    $json =
                        json_decode(
                            $line,
                            true
                        );

                    if ($json) {

                        $errors[] = $json;
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return json_encode([

                'running' =>
                    (bool)(
                        $status['running']
                        ?? false
                    ),

                'stopped' =>
                    (bool)(
                        $status['stopped']
                        ?? false
                    ),

                'processed' =>
                    (int)(
                        $status['processed']
                        ?? 0
                    ),

                'total' =>
                    (int)(
                        $status['total']
                        ?? 0
                    ),

                'percent' =>
                    (float)(
                        $status['percent']
                        ?? 0
                    ),

                'updated_at' =>
                    $status['updated_at']
                    ?? null,

                'inserted' =>
                    $inserted,

                'errors' =>
                    $errors
            ]);
        }
        catch (\Throwable $e) {

            return json_encode([
                'running' => false,
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }
}
