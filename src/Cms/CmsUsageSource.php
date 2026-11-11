<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Cms;

/**
 * Stored facts about Shopping Experience layouts. Ids are hex.
 */
interface CmsUsageSource
{
    /**
     * Every layout (live version): whether it is locked (Shopware's default
     * layouts), the sales channels that use it directly (products, landing
     * pages, default layout settings, owners) and the categories that use it.
     *
     * @return array<string, array{locked: bool, channels: list<string>, categories: list<string>}>
     */
    public function pages(): array;

    /**
     * The layout a stored section, block or slot belongs to (hex), null when
     * it doesn't exist. Primary key in storage format (bytes).
     *
     * @param array<string, string> $primaryKey
     */
    public function pageOf(string $entity, array $primaryKey): ?string;

    /**
     * Records the sales channels of a new layout's creator.
     *
     * @param list<string> $salesChannelIds
     */
    public function addOwners(string $pageId, array $salesChannelIds): void;
}
