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

    /**
     * Sales channels (hex) a product is sold in: its own visibilities, or its
     * parent's when it has none (variants). Null when the product doesn't exist.
     *
     * @return list<string>|null
     */
    public function productChannels(string $productId): ?array;

    /**
     * Product id (bytes) of a stored record in a product table (column product_id).
     *
     * @param array<string, string> $primaryKey
     */
    public function productIdOf(string $entity, array $primaryKey): ?string;

    /**
     * Stored product id (bytes) and sales channel id (hex) of a record with
     * both columns (product_visibility, main_category).
     *
     * @param array<string, string> $primaryKey
     *
     * @return array{productId: string, salesChannelId: string}|null
     */
    public function productChannelRecord(string $entity, array $primaryKey): ?array;

    /**
     * Sales channel id (hex) of a stored product review.
     *
     * @param array<string, string> $primaryKey
     */
    public function reviewChannel(array $primaryKey): ?string;

    /**
     * Sales channel id (hex) of a stored record of a sales channel setting
     * (ScopeRules::CHANNEL_SETTINGS): '' when it applies to all channels,
     * null when it doesn't exist.
     *
     * @param array<string, string> $primaryKey
     */
    public function settingChannel(string $entity, array $primaryKey): ?string;

    /**
     * Configuration key of a stored system_config record, null when it doesn't exist.
     *
     * @param array<string, string> $primaryKey
     */
    public function configKey(array $primaryKey): ?string;

    /**
     * Sales channels (hex) of a stored landing page, null when it doesn't exist.
     *
     * @return list<string>|null
     */
    public function landingPageChannels(string $landingPageId, string $versionId): ?array;

    /**
     * Sales channels (hex) that use a stored analytics record, null when it doesn't exist.
     *
     * @return list<string>|null
     */
    public function analyticsChannels(string $analyticsId): ?array;

    /**
     * Sales channels (hex) a theme is assigned to (bytes id).
     *
     * @return list<string>
     */
    public function themeChannels(string $themeId): array;
}
