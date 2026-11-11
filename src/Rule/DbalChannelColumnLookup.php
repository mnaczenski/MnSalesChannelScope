<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Doctrine\DBAL\Connection;

/**
 * @internal
 */
class DbalChannelColumnLookup implements ChannelColumnLookup
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function channelOf(string $table, string $column, array $primaryKey): ?string
    {
        if (!preg_match(self::IDENTIFIER, $table) || !preg_match(self::IDENTIFIER, $column) || $primaryKey === []) {
            return null;
        }

        $where = [];
        $params = [];
        foreach ($primaryKey as $key => $value) {
            if (!preg_match(self::IDENTIFIER, $key)) {
                return null;
            }
            $where[] = '`' . $key . '` = :pk_' . $key;
            $params['pk_' . $key] = $value;
        }

        $value = $this->connection->fetchOne(
            \sprintf('SELECT IFNULL(LOWER(HEX(`%s`)), \'\') FROM `%s` WHERE %s', $column, $table, implode(' AND ', $where)),
            $params,
        );

        return \is_string($value) ? $value : null;
    }
}
