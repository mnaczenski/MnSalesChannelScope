<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Write;

use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Scope\ScopeRules;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class DbalScopeLookup implements ScopeLookup
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function orderChannel(string $orderId, string $versionId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM `order` WHERE id = :id AND version_id = :version',
            ['id' => $orderId, 'version' => $versionId],
        );

        return \is_string($value) ? $value : null;
    }

    public function orderChannelOf(string $entity, array $primaryKey): ?string
    {
        if (!\in_array($entity, ScopeRules::ORDER_CHILDREN, true) || $primaryKey === []) {
            return null;
        }

        [$where, $params] = $this->where('child', $primaryKey);
        $value = $this->connection->fetchOne(
            'SELECT LOWER(HEX(o.sales_channel_id)) FROM `' . $entity . '` child
             INNER JOIN `order` o ON o.id = child.order_id AND o.version_id = child.order_version_id
             WHERE ' . $where,
            $params,
        );

        return \is_string($value) ? $value : null;
    }

    public function customerChannels(string $customerId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sc, LOWER(HEX(bound_sales_channel_id)) AS bound FROM customer WHERE id = :id',
            ['id' => $customerId],
        );

        return $row === false ? null : ['salesChannelId' => (string) $row['sc'], 'boundSalesChannelId' => $row['bound']];
    }

    public function customerChannelsOfAddress(array $primaryKey): ?array
    {
        if ($primaryKey === []) {
            return null;
        }

        [$where, $params] = $this->where('address', $primaryKey);
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(c.sales_channel_id)) AS sc, LOWER(HEX(c.bound_sales_channel_id)) AS bound
             FROM customer_address address INNER JOIN customer c ON c.id = address.customer_id
             WHERE ' . $where,
            $params,
        );

        return $row === false ? null : ['salesChannelId' => (string) $row['sc'], 'boundSalesChannelId' => $row['bound']];
    }

    public function productChannels(string $productId): ?array
    {
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $parentId = $this->connection->fetchOne(
            'SELECT IFNULL(parent_id, "") FROM product WHERE id = :id AND version_id = :version',
            ['id' => $productId, 'version' => $live],
        );
        if ($parentId === false) {
            return null;
        }

        $channels = $this->visibilities($productId, $live);
        if ($channels === [] && $parentId !== '') {
            $channels = $this->visibilities((string) $parentId, $live);
        }

        return $channels;
    }

    public function productIdOf(string $entity, array $primaryKey): ?string
    {
        if (!preg_match('/^[a-z_]+$/', $entity) || $primaryKey === []) {
            return null;
        }
        if (isset($primaryKey['product_id'])) {
            return $primaryKey['product_id'];
        }

        [$where, $params] = $this->where('child', $primaryKey);
        $value = $this->connection->fetchOne('SELECT product_id FROM `' . $entity . '` child WHERE ' . $where, $params);

        return \is_string($value) ? $value : null;
    }

    public function productChannelRecord(string $entity, array $primaryKey): ?array
    {
        if (!\in_array($entity, ['product_visibility', 'main_category'], true) || $primaryKey === []) {
            return null;
        }

        [$where, $params] = $this->where('child', $primaryKey);
        $row = $this->connection->fetchAssociative(
            'SELECT product_id, LOWER(HEX(sales_channel_id)) AS sales_channel_id FROM `' . $entity . '` child WHERE ' . $where,
            $params,
        );

        return $row === false ? null : ['productId' => (string) $row['product_id'], 'salesChannelId' => (string) $row['sales_channel_id']];
    }

    public function reviewChannel(array $primaryKey): ?string
    {
        [$where, $params] = $this->where('review', $primaryKey);
        $value = $this->connection->fetchOne('SELECT LOWER(HEX(sales_channel_id)) FROM product_review review WHERE ' . $where, $params);

        return \is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function visibilities(string $productId, string $versionId): array
    {
        return array_values(array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(sales_channel_id)) FROM product_visibility WHERE product_id = :id AND product_version_id = :version',
            ['id' => $productId, 'version' => $versionId],
        )));
    }

    /**
     * @param array<string, string> $primaryKey storage name => bytes
     *
     * @return array{string, array<string, string>}
     */
    private function where(string $alias, array $primaryKey): array
    {
        $parts = [];
        $params = [];
        foreach ($primaryKey as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', $column)) {
                continue;
            }
            $parts[] = $alias . '.`' . $column . '` = :pk_' . $column;
            $params['pk_' . $column] = $value;
        }

        return [implode(' AND ', $parts) ?: '1 = 0', $params];
    }
}
