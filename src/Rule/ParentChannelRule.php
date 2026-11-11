<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;

/**
 * An entity whose records belong to the sales channel of their parent, which
 * has a sales channel column (wishlist products follow their wishlist).
 *
 *     $services->set('acme.scope.wishlist_product', ParentChannelRule::class)
 *         ->args(['customer_wishlist_product', 'wishlist', 'customer_wishlist_id', 'customer_wishlist', service(ChannelColumnLookup::class)])
 *         ->tag(Tags::READ_RULE)
 *         ->tag(Tags::WRITE_RULE);
 */
final class ParentChannelRule implements ReadRule, WriteRule
{
    public function __construct(
        private readonly string $entity,
        private readonly string $association,
        private readonly string $parentColumn,
        private readonly string $parentEntity,
        private readonly ChannelColumnLookup $lookup,
        private readonly string $channelField = 'salesChannelId',
        private readonly string $channelColumn = 'sales_channel_id',
    ) {
    }

    public function readFilter(string $entity, array $salesChannelIds): ?Filter
    {
        return $entity === $this->entity ? new EqualsAnyFilter($this->association . '.' . $this->channelField, $salesChannelIds) : null;
    }

    public function deny(WriteCommand $command, WriteScope $scope): ?string
    {
        if ($command->getEntityName() !== $this->entity) {
            return null;
        }

        if (!$command instanceof InsertCommand) {
            $parent = $this->lookup->channelOf($this->entity, $this->parentColumn, $command->getPrimaryKey());
            if ($parent === null || $parent === '' || !$scope->isOwn($this->parentChannel($parent, $scope))) {
                return 'it belongs to another sales channel';
            }
        }

        $payload = $command->getPayload();
        if ($command instanceof InsertCommand || \array_key_exists($this->parentColumn, $payload)) {
            $parentId = $payload[$this->parentColumn] ?? null;
            if (!\is_string($parentId) || \strlen($parentId) !== 16 || !$scope->isOwn($this->parentChannel(bin2hex($parentId), $scope))) {
                return 'it can only belong to your sales channels';
            }
        }

        return null;
    }

    /**
     * @param string $parentId hex
     */
    private function parentChannel(string $parentId, WriteScope $scope): ?string
    {
        $insert = $scope->insertOf($this->parentEntity, $parentId);
        if ($insert !== null) {
            $channel = $insert->getPayload()[$this->channelColumn] ?? null;

            return \is_string($channel) ? $channel : null;
        }

        $channel = $this->lookup->channelOf($this->parentEntity, $this->channelColumn, ['id' => (string) hex2bin($parentId)]);

        return $channel === '' ? null : $channel;
    }
}
