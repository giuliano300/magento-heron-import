<?php

namespace Heron\Bulk\Service;

use Magento\Framework\App\ResourceConnection;

class AttributeRepository
{
    private ResourceConnection $resource;

    private array $cache = [];

    public function __construct(
        ResourceConnection $resource
    ) {
        $this->resource = $resource;
    }

    public function getAttribute(string $code): ?array
    {
        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        $connection = $this->resource->getConnection();

        $table = $this->resource->getTableName(
            'eav_attribute'
        );

        $select = $connection->select()
            ->from($table)
            ->where('attribute_code = ?', $code)
            ->where('entity_type_id = 4');

        $attribute = $connection->fetchRow($select);

        $this->cache[$code] = $attribute;

        return $attribute;
    }
}