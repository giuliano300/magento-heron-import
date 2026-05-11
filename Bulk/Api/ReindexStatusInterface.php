<?php

namespace Heron\Bulk\Api;

interface ReindexStatusInterface
{
    /**
     * @param string $batchId
     * @return string
     */
    public function execute(string $batchId);
}