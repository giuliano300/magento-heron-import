<?php

namespace Heron\Bulk\Api;

interface DeleteProductsInterface
{
    /**
     * @param string $confirmationCode
     * @return string
     */
    public function execute(
        string $confirmationCode = ''
    );
}
