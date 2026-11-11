<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

/**
 * Limits what a restricted admin user reads of an entity.
 *
 * Register an implementation as a service with the tag
 * Tags::READ_RULE ("mn_channel_scope.read_rule"). For every search and aggregation of a
 * restricted user (and every to-many association loaded with it), each rule
 * is asked for a filter; all returned filters apply (AND), so a rule can only
 * narrow what the user sees.
 */
interface ReadRule
{
    /**
     * The filter for this entity, or null when the rule doesn't concern it.
     *
     * @param list<string> $salesChannelIds the user's sales channels (hex)
     */
    public function readFilter(string $entity, array $salesChannelIds): ?Filter;
}
