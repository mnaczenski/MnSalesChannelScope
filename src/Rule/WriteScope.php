<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * What a WriteRule knows about the write it checks: the user's sales
 * channels, the whole write (to accept records inserted together, like an
 * order with its line items) and the context.
 */
final class WriteScope
{
    /** @var array<string, true> */
    private readonly array $allowed;

    /** @var array<string, array<string, InsertCommand>>|null entity => hex id => command */
    private ?array $inserted = null;

    /**
     * @param list<string> $salesChannelIds hex
     * @param list<WriteCommand> $commands
     */
    public function __construct(
        private readonly array $salesChannelIds,
        private readonly array $commands,
        private readonly Context $context,
    ) {
        $this->allowed = array_fill_keys(array_map('strtolower', $salesChannelIds), true);
    }

    /**
     * @return list<string>
     */
    public function salesChannelIds(): array
    {
        return $this->salesChannelIds;
    }

    /**
     * Whether a sales channel (hex, or bytes as stored) is one of the user's.
     */
    public function isOwn(?string $salesChannelId): bool
    {
        if ($salesChannelId === null) {
            return false;
        }
        if (\strlen($salesChannelId) === 16) {
            $salesChannelId = Uuid::fromBytesToHex($salesChannelId);
        }

        return isset($this->allowed[strtolower($salesChannelId)]);
    }

    /**
     * Whether every sales channel is one of the user's (and there is one).
     *
     * @param list<string>|null $salesChannelIds hex
     */
    public function ownsAll(?array $salesChannelIds): bool
    {
        if ($salesChannelIds === null || $salesChannelIds === []) {
            return false;
        }
        foreach ($salesChannelIds as $id) {
            if (!$this->isOwn($id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<WriteCommand>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * The insert of a record in this write (hex id), if any.
     */
    public function insertOf(string $entity, string $id): ?InsertCommand
    {
        if ($this->inserted === null) {
            $this->inserted = [];
            foreach ($this->commands as $command) {
                $key = $command->getPrimaryKey()['id'] ?? null;
                if ($command instanceof InsertCommand && \is_string($key) && \strlen($key) === 16) {
                    $this->inserted[$command->getEntityName()][Uuid::fromBytesToHex($key)] = $command;
                }
            }
        }

        return $this->inserted[$entity][strtolower($id)] ?? null;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
