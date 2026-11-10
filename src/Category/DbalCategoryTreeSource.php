<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
class DbalCategoryTreeSource implements CategoryTreeSource, ResetInterface
{
    /** @var array<string, list<string>>|null */
    private ?array $entryPoints = null;

    /** @var array<string, ?string> */
    private array $paths = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function entryPoints(): array
    {
        if ($this->entryPoints !== null) {
            return $this->entryPoints;
        }

        $this->entryPoints = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(navigation_category_id)) AS navigation,
                    LOWER(HEX(footer_category_id)) AS footer, LOWER(HEX(service_category_id)) AS service
             FROM sales_channel',
        );
        foreach ($rows as $row) {
            $this->entryPoints[$row['id']] = array_values(array_unique(array_filter(
                [$row['navigation'], $row['footer'], $row['service']],
                static fn (?string $id) => $id !== null && $id !== '',
            )));
        }

        return $this->entryPoints;
    }

    public function paths(array $categoryIds): array
    {
        $missing = array_values(array_diff(array_map('strtolower', $categoryIds), array_keys($this->paths)));
        if ($missing !== []) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) AS id, path FROM category WHERE id IN (:ids) AND version_id = :version',
                ['ids' => Uuid::fromHexToBytesList($missing), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                ['ids' => ArrayParameterType::BINARY],
            );
            foreach ($rows as $row) {
                $this->paths[$row['id']] = $row['path'] === null || $row['path'] === '' ? null : (string) $row['path'];
            }
        }

        $result = [];
        foreach ($categoryIds as $id) {
            $id = strtolower($id);
            if (\array_key_exists($id, $this->paths)) {
                $result[$id] = $this->paths[$id];
            }
        }

        return $result;
    }

    public function reset(): void
    {
        $this->entryPoints = null;
        $this->paths = [];
    }
}
