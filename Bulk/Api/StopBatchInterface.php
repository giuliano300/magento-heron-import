<?php

namespace Heron\Bulk\Api;

interface StopBatchInterface
{
    /**
     * @param string $batchId
     * @return array
     */
    public function execute(string $batchId): array;
}
