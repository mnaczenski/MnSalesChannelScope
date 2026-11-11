<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Cms;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which sales channels a Shopping Experience layout belongs to: those of the
 * categories, products and landing pages that use it, of the default layout
 * settings that name it, and, for a layout created by a limited user, that
 * user's channels. For a limited user:
 *
 * - visible: layouts of one of their channels, and Shopware's locked default
 *   layouts (read-only for everyone);
 * - editable: layouts that belong to their channels only;
 * - hidden: everything else, including layouts nothing uses that were created
 *   by unrestricted users.
 */
class CmsPages implements ResetInterface
{
    /** @var array<string, list<string>> visible layout ids by channel list */
    private array $visible = [];

    public function __construct(
        private readonly CmsUsageSource $source,
        private readonly CategoryTrees $categories,
    ) {
    }

    /**
     * Sales channels of a layout, null when it doesn't exist.
     *
     * @return list<string>|null
     */
    public function channels(string $pageId): ?array
    {
        $page = $this->source->pages()[strtolower($pageId)] ?? null;
        if ($page === null) {
            return null;
        }

        $channels = $page['channels'];
        foreach ($page['categories'] as $category) {
            array_push($channels, ...($this->categories->owners($category) ?? []));
        }

        return array_values(array_unique($channels));
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<string>
     */
    public function visibleIds(array $salesChannelIds): array
    {
        $key = implode(',', $salesChannelIds);
        if (isset($this->visible[$key])) {
            return $this->visible[$key];
        }

        $visible = [];
        foreach ($this->source->pages() as $id => $page) {
            if ($page['locked'] || array_intersect($this->channels($id) ?? [], $salesChannelIds) !== []) {
                $visible[] = $id;
            }
        }

        return $this->visible[$key] = $visible;
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function isVisible(string $pageId, array $salesChannelIds): bool
    {
        $page = $this->source->pages()[strtolower($pageId)] ?? null;

        return $page !== null && ($page['locked'] || array_intersect($this->channels($pageId) ?? [], $salesChannelIds) !== []);
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function isEditable(string $pageId, array $salesChannelIds): bool
    {
        $page = $this->source->pages()[strtolower($pageId)] ?? null;
        if ($page === null || $page['locked']) {
            return false;
        }
        $channels = $this->channels($pageId) ?? [];

        return $channels !== [] && array_diff($channels, $salesChannelIds) === [];
    }

    /**
     * @param array<string, string> $primaryKey
     */
    public function pageOf(string $entity, array $primaryKey): ?string
    {
        return $this->source->pageOf($entity, $primaryKey);
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function addOwners(string $pageId, array $salesChannelIds): void
    {
        $this->source->addOwners(strtolower($pageId), $salesChannelIds);
        $this->visible = [];
    }

    public function reset(): void
    {
        $this->visible = [];
    }
}
