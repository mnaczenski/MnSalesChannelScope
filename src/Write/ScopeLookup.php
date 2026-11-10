<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Write;

/**
 * Reads the stored sales channels of records that are about to change.
 * Ids and primary keys are in storage format (bytes).
 */
interface ScopeLookup
{
    /** Sales channel id (hex) of an order, null when it doesn't exist. */
    public function orderChannel(string $orderId, string $versionId): ?string;

    /**
     * Sales channel id (hex) of the order a record in an order table belongs to.
     *
     * @param array<string, string> $primaryKey
     */
    public function orderChannelOf(string $entity, array $primaryKey): ?string;

    /**
     * Registered and bound sales channel (hex) of a customer, null when it doesn't exist.
     *
     * @return array{salesChannelId: string, boundSalesChannelId: ?string}|null
     */
    public function customerChannels(string $customerId): ?array;

    /**
     * @param array<string, string> $primaryKey
     *
     * @return array{salesChannelId: string, boundSalesChannelId: ?string}|null
     */
    public function customerChannelsOfAddress(array $primaryKey): ?array;
}
