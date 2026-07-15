<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\ImportImagesLocalInterface;
use Heron\Bulk\Service\ImageBulkDbImportService;

class ImportImagesLocal
    implements ImportImagesLocalInterface
{
    private ImageBulkDbImportService $service;

    public function __construct(
        ImageBulkDbImportService $service
    ) {
        $this->service = $service;
    }

    public function execute(
        string $batchId
    ): array {

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $batchId)) {

            return [
                'success' => false,
                'message' => 'Batch ID non valido'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORT DIR
        |--------------------------------------------------------------------------
        */

        $importDir =
            BP
            . '/var/import/images';

        /*
        |--------------------------------------------------------------------------
        | ZIP FILE
        |--------------------------------------------------------------------------
        */

        $zipFile =
            $importDir
            . '/'
            . $batchId
            . '.zip';

        /*
        |--------------------------------------------------------------------------
        | ZIP EXISTS
        |--------------------------------------------------------------------------
        */

        if (!file_exists($zipFile)) {

            return [
                'success' => false,
                'message' => 'ZIP non trovato'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | LOG DIR
        |--------------------------------------------------------------------------
        */

        $logDir =
            $importDir
            . '/logs';

        if (!is_dir($logDir)) {

            mkdir(
                $logDir,
                0777,
                true
            );
        }

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

        $stopFile =
            $logDir
            . '/'
            . $batchId
            . '.stop';

        /*
        |--------------------------------------------------------------------------
        | CLEAN OLD FILES
        |--------------------------------------------------------------------------
        */

        @unlink($statusFile);
        @unlink($insertedFile);
        @unlink($errorFile);
        @unlink($stopFile);

        /*
        |--------------------------------------------------------------------------
        | INIT STATUS
        |--------------------------------------------------------------------------
        */

        file_put_contents(
            $statusFile,
            json_encode(
                [
                    'running' => true,

                    'stopped' => false,

                    'processed' => 0,

                    'total' => 0,

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
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        $response = [
            'success' => true,
            'message' => 'Import avviato',
            'batch_id' => $batchId
        ];

        /*
        |--------------------------------------------------------------------------
        | IGNORE CLIENT ABORT
        |--------------------------------------------------------------------------
        */

        ignore_user_abort(true);

        /*
        |--------------------------------------------------------------------------
        | REMOVE OUTPUT BUFFER
        |--------------------------------------------------------------------------
        */

        while (ob_get_level() > 0) {

            ob_end_clean();
        }

        /*
        |--------------------------------------------------------------------------
        | SEND RESPONSE
        |--------------------------------------------------------------------------
        */

        $json =
            json_encode($response);

        header(
            'Content-Type: application/json'
        );

        header(
            'Connection: close'
        );

        header(
            'Content-Length: '
            . strlen($json)
        );

        echo $json;

        /*
        |--------------------------------------------------------------------------
        | FORCE RESPONSE
        |--------------------------------------------------------------------------
        */

        session_write_close();

        flush();

        if (
            function_exists(
                'fastcgi_finish_request'
            )
        ) {

            fastcgi_finish_request();
        }

        /*
        |--------------------------------------------------------------------------
        | CONTINUE IN BACKGROUND
        |--------------------------------------------------------------------------
        */

        try {

            $this->service
                ->import($batchId);

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | UPDATE STATUS
            |--------------------------------------------------------------------------
            */

            file_put_contents(
                $statusFile,
                json_encode(
                    [
                        'running' => false,

                        'processed' => 0,

                        'total' => 0,

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
            | APPEND ERROR
            |--------------------------------------------------------------------------
            */

            file_put_contents(
                $errorFile,
                json_encode(
                    [
                        'sku' => '',
                        'message' =>
                            $e->getMessage()
                    ]
                ) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANTISSIMO
        |--------------------------------------------------------------------------
        */

        exit;
    }
}
