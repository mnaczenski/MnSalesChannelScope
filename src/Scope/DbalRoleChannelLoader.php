<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class DbalRoleChannelLoader implements RoleChannelLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(role.id)) AS role_id, LOWER(HEX(mapping.sales_channel_id)) AS sales_channel_id
             FROM acl_user_role user_role
             INNER JOIN acl_role role ON role.id = user_role.acl_role_id AND role.deleted_at IS NULL
             LEFT JOIN mn_acl_role_sales_channel mapping ON mapping.acl_role_id = role.id
             WHERE user_role.user_id = :userId',
            ['userId' => Uuid::fromHexToBytes($userId)],
        );

        $roles = [];
        foreach ($rows as $row) {
            $roles[$row['role_id']] ??= [];
            if ($row['sales_channel_id'] !== null) {
                $roles[$row['role_id']][] = $row['sales_channel_id'];
            }
        }

        return $roles;
    }
}
