<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Stored records by hex id.
 */
final class StaticScopeLookup implements ScopeLookup
{
    /**
     * @param array<string, string> $orders order id => sales channel id
     * @param array<string, string> $children child id => order id
     * @param array<string, array{salesChannelId: string, boundSalesChannelId: ?string}> $customers
     * @param array<string, string> $addresses address id => customer id
     */
    public function __construct(
        private readonly array $orders = [],
        private readonly array $children = [],
        private readonly array $customers = [],
        private readonly array $addresses = [],
    ) {
    }

    public function orderChannel(string $orderId, string $versionId): ?string
    {
        return $this->orders[Uuid::fromBytesToHex($orderId)] ?? null;
    }

    public function orderChannelOf(string $entity, array $primaryKey): ?string
    {
        $order = $this->children[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;

        return $order === null ? null : ($this->orders[$order] ?? null);
    }

    public function customerChannels(string $customerId): ?array
    {
        return $this->customers[Uuid::fromBytesToHex($customerId)] ?? null;
    }

    public function customerChannelsOfAddress(array $primaryKey): ?array
    {
        $customer = $this->addresses[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;

        return $customer === null ? null : ($this->customers[$customer] ?? null);
    }
}
