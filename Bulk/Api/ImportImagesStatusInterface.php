<?php

namespace Heron\Bulk\Api;

interface ImportImagesStatusInterface
{
    /**
     * @param string $batchId
     * @return string
     */
    public function execute(
        string $batchId
    ): string;
}