<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Cms;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
class DbalCmsUsageSource implements CmsUsageSource, ResetInterface
{
    /** @var array<string, array{locked: bool, channels: list<string>, categories: list<string>}>|null */
    private ?array $pages = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $pages = [];
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, locked FROM cms_page WHERE version_id = :v', ['v' => $live]) as $row) {
            $pages[$row['id']] = ['locked' => (bool) $row['locked'], 'channels' => [], 'categories' => []];
        }

        $add = static function (array &$pages, ?string $page, string $key, ?string $value): void {
            if ($page !== null && $value !== null && $value !== '' && isset($pages[$page]) && !\in_array($value, $pages[$page][$key], true)) {
                $pages[$page][$key][] = $value;
            }
        };

        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(cms_page_id)) AS page, LOWER(HEX(id)) AS category FROM category WHERE cms_page_id IS NOT NULL AND version_id = :v',
            ['v' => $live],
        ) as $row) {
            $add($pages, $row['page'], 'categories', $row['category']);
        }

        // Products and variants (a variant without its own layout and visibilities uses its parent's).
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(IFNULL(p.cms_page_id, parent.cms_page_id))) AS page, LOWER(HEX(vis.sales_channel_id)) AS channel
             FROM product p
             LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             JOIN product_visibility vis ON vis.product_version_id = p.version_id AND (vis.product_id = p.id OR vis.product_id = p.parent_id)
             WHERE p.version_id = :v AND IFNULL(p.cms_page_id, parent.cms_page_id) IS NOT NULL',
            ['v' => $live],
        ) as $row) {
            $add($pages, $row['page'], 'channels', $row['channel']);
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(lp.cms_page_id)) AS page, LOWER(HEX(lps.sales_channel_id)) AS channel
             FROM landing_page lp
             JOIN landing_page_sales_channel lps ON lps.landing_page_id = lp.id AND lps.landing_page_version_id = lp.version_id
             WHERE lp.version_id = :v AND lp.cms_page_id IS NOT NULL',
            ['v' => $live],
        ) as $row) {
            $add($pages, $row['page'], 'channels', $row['channel']);
        }

        // Default layouts (Settings → Shopping Experiences): for all channels or for one.
        $allChannels = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM sales_channel');
        foreach ($this->connection->fetchAllAssociative(
            "SELECT configuration_value AS value, LOWER(HEX(sales_channel_id)) AS channel FROM system_config WHERE configuration_key LIKE 'core.cms.default\\_%'",
        ) as $row) {
            $page = strtolower((string) (json_decode((string) $row['value'], true)['_value'] ?? ''));
            foreach ($row['channel'] === null ? $allChannels : [$row['channel']] as $channel) {
                $add($pages, $page, 'channels', $channel);
            }
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(cms_page_id)) AS page, LOWER(HEX(sales_channel_id)) AS channel FROM mn_cms_page_sales_channel WHERE cms_page_version_id = :v',
            ['v' => $live],
        ) as $row) {
            $add($pages, $row['page'], 'channels', $row['channel']);
        }

        return $this->pages = $pages;
    }

    public function pageOf(string $entity, array $primaryKey): ?string
    {
        $sql = match ($entity) {
            'cms_section' => 'SELECT LOWER(HEX(s.cms_page_id)) FROM cms_section s WHERE s.id = :id AND s.version_id = :v',
            'cms_block' => 'SELECT LOWER(HEX(s.cms_page_id)) FROM cms_block b
                JOIN cms_section s ON s.id = b.cms_section_id AND s.version_id = b.cms_section_version_id
                WHERE b.id = :id AND b.version_id = :v',
            'cms_slot' => 'SELECT LOWER(HEX(s.cms_page_id)) FROM cms_slot sl
                JOIN cms_block b ON b.id = sl.cms_block_id AND b.version_id = sl.cms_block_version_id
                JOIN cms_section s ON s.id = b.cms_section_id AND s.version_id = b.cms_section_version_id
                WHERE sl.id = :id AND sl.version_id = :v',
            default => null,
        };
        if ($sql === null || !\is_string($primaryKey['id'] ?? null)) {
            return null;
        }

        $value = $this->connection->fetchOne($sql, [
            'id' => $primaryKey['id'],
            'v' => $primaryKey['version_id'] ?? Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);

        return \is_string($value) ? $value : null;
    }

    public function addOwners(string $pageId, array $salesChannelIds): void
    {
        foreach ($salesChannelIds as $salesChannelId) {
            $this->connection->executeStatement(
                'INSERT IGNORE INTO mn_cms_page_sales_channel (cms_page_id, cms_page_version_id, sales_channel_id) VALUES (:page, :v, :channel)',
                ['page' => Uuid::fromHexToBytes($pageId), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'channel' => Uuid::fromHexToBytes($salesChannelId)],
            );
        }
        $this->pages = null;
    }

    public function reset(): void
    {
        $this->pages = null;
    }
}
