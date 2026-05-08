<?php

namespace Heron\Bulk\Api;

interface ImagesInterface
{
    /**
     * @param string $items
     * @return string
     */
    public function import(string $items);
}