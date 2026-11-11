<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Cms\CmsUsageSource;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Layouts for the tests (categories from StaticCategoryTreeSource):
 *
 *   DE_LAYOUT      used by DE_SHOES                    → DE only
 *   SHARED_LAYOUT  used by DE_SHOES and an EN product  → DE and EN
 *   EN_LAYOUT      used by EN_SOFAS                    → EN only
 *   OWNED_LAYOUT   unused, created by a DE user        → DE only
 *   UNUSED_LAYOUT  unused, created by an admin         → none
 *   LOCKED_LAYOUT  Shopware default, locked            → read-only for all
 *
 * Parts: SECTION_DE in DE_LAYOUT, BLOCK_DE in SECTION_DE, SLOT_DE in BLOCK_DE;
 * SECTION_SHARED in SHARED_LAYOUT.
 */
final class StaticCmsUsageSource implements CmsUsageSource
{
    public const DE_LAYOUT = '0190dddddddddddddddddddddddd0001';
    public const SHARED_LAYOUT = '0190dddddddddddddddddddddddd0002';
    public const EN_LAYOUT = '0190dddddddddddddddddddddddd0003';
    public const OWNED_LAYOUT = '0190dddddddddddddddddddddddd0004';
    public const UNUSED_LAYOUT = '0190dddddddddddddddddddddddd0005';
    public const LOCKED_LAYOUT = '0190dddddddddddddddddddddddd0006';

    public const SECTION_DE = '0190dddddddddddddddddddddddd1001';
    public const BLOCK_DE = '0190dddddddddddddddddddddddd2001';
    public const SLOT_DE = '0190dddddddddddddddddddddddd3001';
    public const SECTION_SHARED = '0190dddddddddddddddddddddddd1002';

    /** @var array<string, list<string>> */
    public array $addedOwners = [];

    public function pages(): array
    {
        return [
            self::DE_LAYOUT => ['locked' => false, 'channels' => [], 'categories' => [StaticCategoryTreeSource::DE_SHOES]],
            self::SHARED_LAYOUT => ['locked' => false, 'channels' => [Ids::EN], 'categories' => [StaticCategoryTreeSource::DE_SHOES]],
            self::EN_LAYOUT => ['locked' => false, 'channels' => [], 'categories' => [StaticCategoryTreeSource::EN_SOFAS]],
            self::OWNED_LAYOUT => ['locked' => false, 'channels' => [Ids::DE], 'categories' => []],
            self::UNUSED_LAYOUT => ['locked' => false, 'channels' => [], 'categories' => []],
            self::LOCKED_LAYOUT => ['locked' => true, 'channels' => [Ids::DE, Ids::EN], 'categories' => []],
        ];
    }

    public function pageOf(string $entity, array $primaryKey): ?string
    {
        $id = Uuid::fromBytesToHex($primaryKey['id']);

        return match ([$entity, $id]) {
            ['cms_section', self::SECTION_DE], ['cms_block', self::BLOCK_DE], ['cms_slot', self::SLOT_DE] => self::DE_LAYOUT,
            ['cms_section', self::SECTION_SHARED] => self::SHARED_LAYOUT,
            default => null,
        };
    }

    public function addOwners(string $pageId, array $salesChannelIds): void
    {
        $this->addedOwners[$pageId] = $salesChannelIds;
    }
}
