<?php

namespace Heron\Bulk\Api;

interface ImportInterface
{
    /**
     * @param string $products
     * @return string
     */
    public function import(string  $products);
}