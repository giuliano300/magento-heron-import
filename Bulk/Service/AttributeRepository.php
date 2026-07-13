<?php

namespace Heron\Bulk\Service;

use Magento\Framework\App\ResourceConnection;

class AttributeRepository
{
    private ResourceConnection $resource;

    private array $cache = [];

    private ?int $productEntityTypeId = null;

    public function __construct(
        ResourceConnection $resource
    ) {
        $this->resource = $resource;
    }

    public function getAttribute(string $code): ?array
    {
        if (array_key_exists($code, $this->cache)) {
            return $this->cache[$code];
        }

        $connection = $this->resource->getConnection();

        $table = $this->resource->getTableName(
            'eav_attribute'
        );

        $select = $connection->select()
            ->from($table)
            ->where('attribute_code = ?', $code)
            ->where(
                'entity_type_id = ?',
                $this->getProductEntityTypeId()
            );

        $attribute = $connection->fetchRow($select);

        $this->cache[$code] = is_array($attribute)
            ? $attribute
            : null;

        return $this->cache[$code];
    }

    private function getProductEntityTypeId(): int
    {
        if ($this->productEntityTypeId !== null) {
            return $this->productEntityTypeId;
        }

        $connection = $this->resource->getConnection();

        $table = $this->resource->getTableName(
            'eav_entity_type'
        );

        $entityTypeId = $connection->fetchOne(
            $connection->select()
                ->from(
                    $table,
                    ['entity_type_id']
                )
                ->where(
                    'entity_type_code = ?',
                    'catalog_product'
                )
                ->limit(1)
        );

        if (!$entityTypeId) {
            throw new \RuntimeException(
                'Entity type catalog_product non trovato'
            );
        }

        $this->productEntityTypeId = (int)$entityTypeId;

        return $this->productEntityTypeId;
    }
}
