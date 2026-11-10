<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Category;

/**
 * Where the category trees of the sales channels start, and the stored tree
 * position of categories. Ids in hex.
 */
interface CategoryTreeSource
{
    /**
     * @return array<string, list<string>> sales channel id => its entry categories (navigation, footer, service)
     */
    public function entryPoints(): array;

    /**
     * Stored path ("|root|…|parent|", null for roots) of categories; missing = unknown category.
     *
     * @param list<string> $categoryIds
     *
     * @return array<string, ?string>
     */
    public function paths(array $categoryIds): array;
}
