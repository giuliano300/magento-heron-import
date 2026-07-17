<?php

namespace Heron\Bulk\Service;

use Psr\Log\LoggerInterface;

class MagentoCommandService
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    public function run(
        array $arguments,
        array $phpOptions = []
    ): array
    {
        $command =
            $this->buildCommand(
                $arguments,
                $phpOptions
            );

        $descriptorSpec = [
            1 => [
                'pipe',
                'w'
            ],
            2 => [
                'pipe',
                'w'
            ]
        ];

        $process =
            proc_open(
                $command,
                $descriptorSpec,
                $pipes,
                BP
            );

        if (!is_resource($process)) {

            $this->logger->error(
                '[MAGENTO COMMAND ERROR] Unable to start process: '
                . $command
            );

            return [
                'command' => $command,
                'exit_code' => 1,
                'output' => '',
                'error' => 'Unable to start process'
            ];
        }

        $output =
            stream_get_contents($pipes[1]);

        $error =
            stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode =
            proc_close($process);

        $combinedOutput =
            trim(
                $output
                . PHP_EOL
                . $error
            );

        $context = [
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $combinedOutput
        ];

        if ($exitCode === 0) {

            $this->logger->info(
                '[MAGENTO COMMAND OK]',
                $context
            );

        } else {

            $this->logger->error(
                '[MAGENTO COMMAND FAILED]',
                $context
            );
        }

        return $context;
    }

    public function runBackground(
        array $arguments,
        string $logFile,
        array $phpOptions = []
    ): array {

        $logPath =
            BP
            . '/'
            . ltrim(
                $logFile,
                '/'
            );

        $logDir =
            dirname($logPath);

        if (!is_dir($logDir)) {

            mkdir(
                $logDir,
                0775,
                true
            );
        }

        $command =
            'nohup '
            . $this->buildCommand(
                $arguments,
                $phpOptions
            )
            . ' > '
            . escapeshellarg($logPath)
            . ' 2>&1 & echo $!';

        $descriptorSpec = [
            1 => [
                'pipe',
                'w'
            ],
            2 => [
                'pipe',
                'w'
            ]
        ];

        $process =
            proc_open(
                $command,
                $descriptorSpec,
                $pipes,
                BP
            );

        if (!is_resource($process)) {

            $this->logger->error(
                '[MAGENTO BACKGROUND COMMAND ERROR] Unable to start process: '
                . $command
            );

            return [
                'command' => $command,
                'exit_code' => 1,
                'pid' => null,
                'log_file' => $logPath,
                'error' => 'Unable to start process'
            ];
        }

        $pid =
            trim(
                stream_get_contents($pipes[1])
            );

        $error =
            trim(
                stream_get_contents($pipes[2])
            );

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode =
            proc_close($process);

        $context = [
            'command' => $command,
            'exit_code' => $exitCode,
            'pid' => $pid !== '' ? $pid : null,
            'log_file' => $logPath,
            'error' => $error
        ];

        if ($exitCode === 0) {

            $this->logger->info(
                '[MAGENTO BACKGROUND COMMAND STARTED]',
                $context
            );

        } else {

            $this->logger->error(
                '[MAGENTO BACKGROUND COMMAND FAILED]',
                $context
            );
        }

        return $context;
    }

    private function buildCommand(
        array $arguments,
        array $phpOptions = []
    ): string {

        $phpOptionsPart =
            !empty($phpOptions)
                ? implode(
                    ' ',
                    array_map(
                        'escapeshellarg',
                        $phpOptions
                    )
                ) . ' '
                : '';

        return
            'php '
            . $phpOptionsPart
            . 'bin/magento '
            . implode(
                ' ',
                array_map(
                    'escapeshellarg',
                    $arguments
                )
            );
    }
}
