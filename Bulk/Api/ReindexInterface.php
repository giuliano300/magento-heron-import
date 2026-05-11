<?php

namespace Heron\Bulk\Api;

interface ReindexInterface
{
    /**
     * @param string $batchId
     * @param string[] $skus
     * @return string
     */
    public function execute(
        string $batchId,
        array $skus = []
    );
}