<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Rule\WriteRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * State transitions (state_machine_history) point to their record by id and
 * entity name only, without an association a filter could follow, and carry
 * the user and an internal comment. The order's history (sw-order) asks for
 * the transitions of an order, its deliveries and transactions by
 * referencedId.
 *
 * Limited users see the transitions of records of their orders only: of the
 * referencedIds a search asks for, those of other channels are dropped; a
 * search without referencedIds finds nothing. New entries only for their
 * orders' records.
 */
class StateHistoryGuardSubscriber implements EventSubscriberInterface, WriteRule
{
    public const ENTITY = 'state_machine_history';

    private const NONE = '00000000000000000000000000000000';

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ScopeLookup $lookup,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntitySearchedEvent::class => 'onSearch',
            BeforeEntityAggregationEvent::class => 'onAggregate',
        ];
    }

    public function onSearch(EntitySearchedEvent $event): void
    {
        if ($event->getDefinition()->getEntityName() === self::ENTITY) {
            $this->restrict($event->getCriteria(), $event->getContext());
        }
    }

    public function onAggregate(BeforeEntityAggregationEvent $event): void
    {
        if ($event->getDefinition()->getEntityName() === self::ENTITY) {
            $this->restrict($event->getCriteria(), $event->getContext());
        }
    }

    public function deny(WriteCommand $command, WriteScope $scope): ?string
    {
        if ($command->getEntityName() !== self::ENTITY) {
            return null;
        }
        if (!$command instanceof InsertCommand) {
            return 'state transitions can\'t be changed';
        }

        $id = $command->getPayload()['referenced_id'] ?? null;
        if (!\is_string($id) || \strlen($id) !== 16) {
            return 'the record belongs to another sales channel';
        }
        $hex = Uuid::fromBytesToHex($id);
        $order = $scope->insertOf('order', $hex);
        if ($order !== null) {
            $channel = $order->getPayload()['sales_channel_id'] ?? null;

            return \is_string($channel) && $scope->isOwn($channel) ? null : 'the record belongs to another sales channel';
        }

        return $this->lookup->ownStateRecords([$hex], $scope->salesChannelIds()) === [$hex] ? null : 'the record belongs to another sales channel';
    }

    private function restrict(Criteria $criteria, Context $context): void
    {
        $salesChannelIds = $this->scope->salesChannelIds($context);
        if ($salesChannelIds === null) {
            return;
        }

        $requested = [];
        foreach ($criteria->getFilters() as $filter) {
            $this->collect($filter, $requested);
        }
        $requested = array_values(array_unique(array_filter($requested, [Uuid::class, 'isValid'])));

        $own = $requested === [] ? [] : $this->lookup->ownStateRecords($requested, $salesChannelIds);
        $criteria->addFilter(new EqualsAnyFilter('referencedId', $own ?: [self::NONE]));
    }

    /**
     * referencedIds a filter asks for, through AND groups only (an OR or NOT
     * group doesn't narrow the search).
     *
     * @param list<string> $ids
     */
    private function collect(Filter $filter, array &$ids): void
    {
        if ($filter instanceof MultiFilter) {
            if ($filter->getOperator() === MultiFilter::CONNECTION_AND) {
                foreach ($filter->getQueries() as $query) {
                    $this->collect($query, $ids);
                }
            }

            return;
        }

        $field = $filter instanceof EqualsFilter || $filter instanceof EqualsAnyFilter ? $filter->getField() : null;
        if ($field !== 'referencedId' && $field !== self::ENTITY . '.referencedId') {
            return;
        }
        $values = $filter instanceof EqualsAnyFilter ? $filter->getValue() : [$filter->getValue()];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $ids[] = strtolower($value);
            }
        }
    }
}
