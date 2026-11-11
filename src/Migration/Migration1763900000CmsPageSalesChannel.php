<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The sales channels a Shopping Experience layout belongs to while nothing
 * uses it yet: the channels of the limited user who created it.
 *
 * @internal
 */
class Migration1763900000CmsPageSalesChannel extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1763900000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `mn_cms_page_sales_channel` (
                `cms_page_id` BINARY(16) NOT NULL,
                `cms_page_version_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                PRIMARY KEY (`cms_page_id`, `cms_page_version_id`, `sales_channel_id`),
                CONSTRAINT `fk.mn_cms_page_sales_channel.cms_page_id` FOREIGN KEY (`cms_page_id`, `cms_page_version_id`)
                    REFERENCES `cms_page` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.mn_cms_page_sales_channel.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
