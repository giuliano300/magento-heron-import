<?php

namespace Heron\Bulk\Api;

interface ImportInterface
{
    /**
     * @param string $products
     * @param string $batchId
     * @return string
     */
    public function import(string $products, string $batchId = '');
}
