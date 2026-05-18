<?php

namespace Heron\Bulk\Api;

interface UpdateQtyInterface
{
    /**
     * @param string $products
     * @return string
     */
    public function execute(string $products);
}