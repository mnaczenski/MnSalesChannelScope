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
     * @param array<string, list<string>> $products product id => sales channel ids
     * @param array<string, string> $productChildren child id => product id
     * @param array<string, array{product: string, channel: string}> $channelRecords visibility or main category id
     * @param array<string, string> $reviews review id => sales channel id
     * @param array<string, string> $settings setting id => sales channel id ('' for all channels)
     * @param array<string, string> $configKeys system_config id => configuration key
     * @param array<string, list<string>> $landingPages landing page id => sales channel ids
     */
    public function __construct(
        private readonly array $orders = [],
        private readonly array $children = [],
        private readonly array $customers = [],
        private readonly array $addresses = [],
        private readonly array $products = [],
        private readonly array $productChildren = [],
        private readonly array $channelRecords = [],
        private readonly array $reviews = [],
        private readonly array $settings = [],
        private readonly array $configKeys = [],
        private readonly array $landingPages = [],
    ) {
    }

    public function productChannels(string $productId): ?array
    {
        return $this->products[Uuid::fromBytesToHex($productId)] ?? null;
    }

    public function productIdOf(string $entity, array $primaryKey): ?string
    {
        if (isset($primaryKey['product_id'])) {
            return $primaryKey['product_id'];
        }
        $product = $this->productChildren[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;

        return $product === null ? null : Uuid::fromHexToBytes($product);
    }

    public function productChannelRecord(string $entity, array $primaryKey): ?array
    {
        $record = $this->channelRecords[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;

        return $record === null ? null : ['productId' => Uuid::fromHexToBytes($record['product']), 'salesChannelId' => $record['channel']];
    }

    public function reviewChannel(array $primaryKey): ?string
    {
        return $this->reviews[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;
    }

    public function settingChannel(string $entity, array $primaryKey): ?string
    {
        return $this->settings[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;
    }

    public function landingPageChannels(string $landingPageId, string $versionId): ?array
    {
        return $this->landingPages[Uuid::fromBytesToHex($landingPageId)] ?? null;
    }

    public function configKey(array $primaryKey): ?string
    {
        return $this->configKeys[Uuid::fromBytesToHex($primaryKey['id'])] ?? null;
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
