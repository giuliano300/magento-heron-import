<?php

namespace Heron\Bulk\Api;

interface ImportImagesLocalInterface
{
    /**
     * @param string $batchId
     * @return array
     */
    public function execute(string $batchId): array;
}