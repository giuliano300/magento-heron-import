<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\UpdateQtyInterface;
use Heron\Bulk\Service\BulkQtyUpdateService;

class UpdateQty
    implements UpdateQtyInterface
{
    private BulkQtyUpdateService $service;

    public function __construct(
        BulkQtyUpdateService $service
    ) {
        $this->service = $service;
    }

    public function execute(string $products)
    {
        return $this->service->update(
            $products
        );
    }
}