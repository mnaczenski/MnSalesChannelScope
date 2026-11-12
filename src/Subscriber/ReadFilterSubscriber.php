<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Rule\ReadRule;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the sales channel filter to every repository search and aggregation
 * of a restricted admin user, and to the to-many associations loaded with
 * it (those are read without a search event of their own).
 *
 * Same mechanism as Shopware's MediaVisibilityRestrictionSubscriber.
 */
class ReadFilterSubscriber implements EventSubscriberInterface
{
    private const MAX_DEPTH = 8;

    /**
     * @param iterable<ReadRule> $rules the built-in ScopeRules and every service tagged Tags::READ_RULE
     */
    public function __construct(
        private readonly ChannelScope $scope,
        private readonly iterable $rules,
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
        $this->restrict($event->getCriteria(), $event->getDefinition(), $event->getContext());
    }

    public function onAggregate(BeforeEntityAggregationEvent $event): void
    {
        $this->restrict($event->getCriteria(), $event->getDefinition(), $event->getContext());
    }

    private function restrict(Criteria $criteria, EntityDefinition $definition, Context $context): void
    {
        $ids = $this->scope->salesChannelIds($context);
        if ($ids === null) {
            return;
        }

        $filter = $this->filter($definition->getEntityName(), $ids);
        if ($filter !== null) {
            self::addFilter($criteria, $definition, $filter);
        }

        $this->restrictAssociations($criteria, $definition, $ids, 0);
    }

    /**
     * When a filter joins a to-many association (a product's visibilities),
     * Shopware groups the rows by the column `id`. Records without one, like
     * translations (key: record and language), would fail with "Unknown
     * column"; they are grouped by their primary key instead.
     */
    private static function addFilter(Criteria $criteria, EntityDefinition $definition, Filter $filter): void
    {
        $criteria->addFilter($filter);

        if ($criteria->getGroupFields() !== [] || $definition->getFields()->has('id')) {
            return;
        }
        foreach ($definition->getPrimaryKeys() as $field) {
            $criteria->addGroupField(new FieldGrouping($field->getPropertyName()));
        }
    }

    /**
     * All rules' filters for an entity, combined with AND.
     *
     * @param list<string> $ids
     */
    private function filter(string $entity, array $ids): ?Filter
    {
        $filters = [];
        foreach ($this->rules as $rule) {
            $filter = $rule->readFilter($entity, $ids);
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return match (\count($filters)) {
            0 => null,
            1 => $filters[0],
            default => new AndFilter($filters),
        };
    }

    /**
     * @param list<string> $ids
     */
    private function restrictAssociations(Criteria $criteria, EntityDefinition $definition, array $ids, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach ($criteria->getAssociations() as $name => $associationCriteria) {
            $field = $definition->getFields()->get($name);
            if (!$field instanceof AssociationField) {
                continue;
            }

            $toMany = $field instanceof ManyToManyAssociationField || $field instanceof OneToManyAssociationField;
            $reference = $field instanceof ManyToManyAssociationField
                ? $field->getToManyReferenceDefinition()
                : $field->getReferenceDefinition();

            // Many-to-one and one-to-one are joined and can't take a filter;
            // their root entity is filtered by its own search instead.
            if ($toMany) {
                $filter = $this->filter($reference->getEntityName(), $ids);
                if ($filter !== null) {
                    self::addFilter($associationCriteria, $reference, $filter);
                }
            }

            $this->restrictAssociations($associationCriteria, $reference, $ids, $depth + 1);
        }
    }
}
