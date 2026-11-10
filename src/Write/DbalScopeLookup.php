<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Write;

use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Scope\ScopeRules;

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
