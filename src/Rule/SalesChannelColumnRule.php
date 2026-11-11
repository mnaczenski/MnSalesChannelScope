<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;

/**
 * The common case: an entity whose records belong to one sales channel
 * through a column (sales_channel_id). Restricted users see and change only
 * records of their channels, can't move them to another channel, and (unless
 * $allowAllChannels) can't touch records without a channel.
 *
 * Register one service per entity, tagged as both a read and a write rule:
 *
 *     $services->set('acme.scope.newsletter_recipient', SalesChannelColumnRule::class)
 *         ->args(['newsletter_recipient', service(ChannelColumnLookup::class)])
 *         ->tag(Tags::READ_RULE)
 *         ->tag(Tags::WRITE_RULE);
 *
 * $allowAllChannels: records without a channel apply to all channels; they
 * are visible, but only unrestricted users change them.
 */
final class SalesChannelColumnRule implements ReadRule, WriteRule
{
    public function __construct(
        private readonly string $entity,
        private readonly ChannelColumnLookup $lookup,
        private readonly string $field = 'salesChannelId',
        private readonly string $column = 'sales_channel_id',
        private readonly bool $allowAllChannels = false,
    ) {
    }

    public function readFilter(string $entity, array $salesChannelIds): ?Filter
    {
        if ($entity !== $this->entity) {
            return null;
        }

        $own = new EqualsAnyFilter($this->field, $salesChannelIds);

        return $this->allowAllChannels ? new OrFilter([$own, new EqualsFilter($this->field, null)]) : $own;
    }

    public function deny(WriteCommand $command, WriteScope $scope): ?string
    {
        if ($command->getEntityName() !== $this->entity) {
            return null;
        }

        if (!$command instanceof InsertCommand) {
            $stored = $this->lookup->channelOf($this->entity, $this->column, $command->getPrimaryKey());
            if ($stored === null || !$scope->isOwn($stored)) {
                return $stored === '' ? 'it applies to all sales channels' : 'it belongs to another sales channel';
            }
        }

        $payload = $command->getPayload();
        if (($command instanceof InsertCommand || \array_key_exists($this->column, $payload)) && !$scope->isOwn($payload[$this->column] ?? null)) {
            return 'it can only belong to your sales channels';
        }

        return null;
    }
}
