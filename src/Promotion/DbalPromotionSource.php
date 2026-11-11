<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Promotion;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class DbalPromotionSource implements PromotionSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function channels(string $promotionId): ?array
    {
        if (!Uuid::isValid($promotionId)) {
            return null;
        }
        $id = Uuid::fromHexToBytes($promotionId);
        if ($this->connection->fetchOne('SELECT 1 FROM promotion WHERE id = :id', ['id' => $id]) === false) {
            return null;
        }

        return array_values(array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(sales_channel_id)) FROM promotion_sales_channel WHERE promotion_id = :id',
            ['id' => $id],
        )));
    }

    public function promotionOf(string $entity, string $id): ?string
    {
        $sql = match ($entity) {
            'promotion_discount', 'promotion_setgroup', 'promotion_individual_code', 'promotion_sales_channel' => 'SELECT LOWER(HEX(promotion_id)) FROM `' . $entity . '` WHERE id = :id',
            'promotion_discount_prices' => 'SELECT LOWER(HEX(d.promotion_id)) FROM promotion_discount_prices p JOIN promotion_discount d ON d.id = p.discount_id WHERE p.id = :id',
            default => null,
        };
        if ($sql === null || !Uuid::isValid($id)) {
            return null;
        }
        $value = $this->connection->fetchOne($sql, ['id' => Uuid::fromHexToBytes($id)]);

        return \is_string($value) ? $value : null;
    }

    public function assignmentChannel(string $id): ?string
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $value = $this->connection->fetchOne('SELECT LOWER(HEX(sales_channel_id)) FROM promotion_sales_channel WHERE id = :id', ['id' => Uuid::fromHexToBytes($id)]);

        return \is_string($value) ? $value : null;
    }
}
