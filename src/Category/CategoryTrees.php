<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Category;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

/**
 * Which categories belong to which sales channels.
 *
 * A sales channel uses the trees below its entry categories (navigation,
 * footer, service). A category belongs to every channel whose entry is the
 * category itself or one of its ancestors. For a limited user:
 *
 * - visible: categories in the trees of their channels, plus the ancestors of
 *   their entry categories (read-only, so the tree can be shown);
 * - editable: categories that belong to their channels only;
 * - hidden: everything else, including trees no channel uses.
 */
class CategoryTrees
{
    public function __construct(private readonly CategoryTreeSource $source)
    {
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function readFilter(array $salesChannelIds, string $prefix = ''): Filter
    {
        $entries = $this->entriesOf($salesChannelIds);
        if ($entries === []) {
            return new EqualsAnyFilter($prefix . 'id', ['00000000000000000000000000000000']);
        }

        $ids = $entries;
        foreach ($this->source->paths($entries) as $path) {
            array_push($ids, ...$this->idsInPath($path));
        }

        $queries = [new EqualsAnyFilter($prefix . 'id', array_values(array_unique($ids)))];
        foreach ($entries as $entry) {
            $queries[] = new ContainsFilter($prefix . 'path', '|' . $entry . '|');
        }

        return new OrFilter($queries);
    }

    /**
     * Sales channels a category belongs to; null when the category is unknown.
     *
     * @return list<string>|null
     */
    public function owners(string $categoryId): ?array
    {
        $categoryId = strtolower($categoryId);
        $paths = $this->source->paths([$categoryId]);
        if (!\array_key_exists($categoryId, $paths)) {
            return null;
        }

        return $this->ownersOf($categoryId, $paths[$categoryId]);
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function isEditable(string $categoryId, array $salesChannelIds): bool
    {
        $owners = $this->owners($categoryId);
        if ($owners === null || $owners === []) {
            return false;
        }

        return array_diff($owners, $salesChannelIds) === [];
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function isVisible(string $categoryId, array $salesChannelIds): bool
    {
        $categoryId = strtolower($categoryId);
        $owners = $this->owners($categoryId);
        if ($owners === null) {
            return false;
        }
        if (array_intersect($owners, $salesChannelIds) !== []) {
            return true;
        }

        $entries = $this->entriesOf($salesChannelIds);
        foreach ($this->source->paths($entries) as $path) {
            if (\in_array($categoryId, $this->idsInPath($path), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entry categories of other channels below a category: deleting or moving
     * it would change those channels' trees.
     *
     * @param list<string> $salesChannelIds
     */
    public function hasForeignEntriesBelow(string $categoryId, array $salesChannelIds): bool
    {
        $categoryId = strtolower($categoryId);
        $foreign = [];
        foreach ($this->source->entryPoints() as $channel => $entries) {
            if (!\in_array($channel, $salesChannelIds, true)) {
                array_push($foreign, ...$entries);
            }
        }
        foreach ($this->source->paths(array_values(array_unique($foreign))) as $entry => $path) {
            if ($entry === $categoryId || \in_array($categoryId, $this->idsInPath($path), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array{own: list<string>, foreign: list<string>}
     */
    public function entryPointsFor(array $salesChannelIds): array
    {
        $own = $this->entriesOf($salesChannelIds);
        $foreign = [];
        foreach ($this->source->entryPoints() as $channel => $entries) {
            if (!\in_array($channel, $salesChannelIds, true)) {
                array_push($foreign, ...$entries);
            }
        }

        return ['own' => $own, 'foreign' => array_values(array_unique($foreign))];
    }

    /**
     * @return list<string>
     */
    private function ownersOf(string $categoryId, ?string $path): array
    {
        $lineage = [...$this->idsInPath($path), $categoryId];
        $owners = [];
        foreach ($this->source->entryPoints() as $channel => $entries) {
            if (array_intersect($entries, $lineage) !== []) {
                $owners[] = $channel;
            }
        }

        return $owners;
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<string>
     */
    private function entriesOf(array $salesChannelIds): array
    {
        $entries = [];
        foreach ($this->source->entryPoints() as $channel => $channelEntries) {
            if (\in_array($channel, $salesChannelIds, true)) {
                array_push($entries, ...$channelEntries);
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * @return list<string>
     */
    private function idsInPath(?string $path): array
    {
        return $path === null ? [] : array_values(array_filter(explode('|', strtolower($path))));
    }
}
