<?php

namespace Heron\Bulk\Model;

use Heron\Bulk\Api\PingInterface;

class Ping implements PingInterface
{
    public function ping()
    {
        return json_encode([
            'success' => true
        ]);
    }
}