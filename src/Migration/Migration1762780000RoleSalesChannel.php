<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The sales channels of an ACL role. A role without rows is unrestricted.
 *
 * @internal
 */
class Migration1762780000RoleSalesChannel extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1762780000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `mn_acl_role_sales_channel` (
                `acl_role_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                PRIMARY KEY (`acl_role_id`, `sales_channel_id`),
                CONSTRAINT `fk.mn_acl_role_sales_channel.acl_role_id` FOREIGN KEY (`acl_role_id`)
                    REFERENCES `acl_role` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.mn_acl_role_sales_channel.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
