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

    public function run(array $arguments): array
    {
        $command =
            'php bin/magento '
            . implode(
                ' ',
                array_map(
                    'escapeshellarg',
                    $arguments
                )
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
}
