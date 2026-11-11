<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Cms;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\CmsGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource;
use Mn\SalesChannelScope\Tests\StaticCmsUsageSource as Cms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsPageTranslation\CmsPageTranslationDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotDefinition;
use Shopware\Core\Content\Cms\CmsPageDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PostWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;

#[CoversClass(CmsGuardSubscriber::class)]
class CmsGuardSubscriberTest extends TestCase
{
    public function testOwnLayoutsAndTheirPartsCanChange(): void
    {
        static::assertSame([], $this->denied([
            $this->update(CmsPageDefinition::class, Cms::DE_LAYOUT, ['type' => 'landingpage']),
            $this->update(CmsBlockDefinition::class, Cms::BLOCK_DE, ['position' => 2]),
            $this->update(CmsSlotDefinition::class, Cms::SLOT_DE, ['slot' => 'left']),
            $this->delete(CmsSectionDefinition::class, Cms::SECTION_DE),
        ]));
        static::assertSame([], $this->denied([$this->update(CmsPageTranslationDefinition::class, null, ['name' => 'x'], ['cms_page_id' => Cms::DE_LAYOUT, 'language_id' => Defaults::LANGUAGE_SYSTEM])]));
    }

    public function testSharedLockedAndForeignLayoutsAreReadOnly(): void
    {
        static::assertSame(['cms_page'], $this->denied([$this->update(CmsPageDefinition::class, Cms::SHARED_LAYOUT, ['type' => 'page'])]));
        static::assertSame(['cms_page'], $this->denied([$this->delete(CmsPageDefinition::class, Cms::LOCKED_LAYOUT)]));
        static::assertSame(['cms_page'], $this->denied([$this->update(CmsPageDefinition::class, Cms::EN_LAYOUT, ['type' => 'page'])]));
        static::assertSame(['cms_section'], $this->denied([$this->update(CmsSectionDefinition::class, Cms::SECTION_SHARED, ['position' => 1])]));
        static::assertSame(['cms_page_translation'], $this->denied([$this->update(CmsPageTranslationDefinition::class, null, ['name' => 'x'], ['cms_page_id' => Cms::SHARED_LAYOUT, 'language_id' => Defaults::LANGUAGE_SYSTEM])]));
    }

    public function testPartsCanNotBeAddedToOrMovedIntoSharedLayouts(): void
    {
        static::assertSame(['cms_section'], $this->denied([$this->insert(CmsSectionDefinition::class, Uuid::randomHex(), ['cms_page_id' => Uuid::fromHexToBytes(Cms::SHARED_LAYOUT)])]));
        static::assertSame(['cms_block'], $this->denied([$this->update(CmsBlockDefinition::class, Cms::BLOCK_DE, ['cms_section_id' => Uuid::fromHexToBytes(Cms::SECTION_SHARED)])]));
        static::assertSame([], $this->denied([$this->insert(CmsBlockDefinition::class, Uuid::randomHex(), ['cms_section_id' => Uuid::fromHexToBytes(Cms::SECTION_DE)])]));
    }

    public function testNewLayoutsWithTheirPartsAreAllowed(): void
    {
        $page = Uuid::randomHex();
        $section = Uuid::randomHex();
        $block = Uuid::randomHex();

        static::assertSame([], $this->denied([
            $this->insert(CmsPageDefinition::class, $page, ['type' => 'page']),
            $this->insert(CmsSectionDefinition::class, $section, ['cms_page_id' => Uuid::fromHexToBytes($page)]),
            $this->insert(CmsBlockDefinition::class, $block, ['cms_section_id' => Uuid::fromHexToBytes($section)]),
            $this->insert(CmsSlotDefinition::class, Uuid::randomHex(), ['cms_block_id' => Uuid::fromHexToBytes($block)]),
        ]));
    }

    public function testCategoriesOnlyGetVisibleLayouts(): void
    {
        static::assertSame([], $this->denied([$this->update(CategoryDefinition::class, StaticCategoryTreeSource::DE_SHOES, ['cms_page_id' => Uuid::fromHexToBytes(Cms::SHARED_LAYOUT)])]));
        static::assertSame(['category'], $this->denied([$this->update(CategoryDefinition::class, StaticCategoryTreeSource::DE_SHOES, ['cms_page_id' => Uuid::fromHexToBytes(Cms::EN_LAYOUT)])]));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertSame([], $this->denied([$this->delete(CmsPageDefinition::class, Cms::EN_LAYOUT)], Ids::context(Ids::FREE_USER)));
    }

    public function testNewLayoutsBelongToTheCreatorsChannels(): void
    {
        $source = new Cms();
        $page = Uuid::randomHex();
        $event = new PostWriteValidationEvent(WriteContext::createFromContext(Ids::context()), [$this->insert(CmsPageDefinition::class, $page, ['type' => 'page'])]);
        (new CmsGuardSubscriber(Ids::scope(), new CmsPages($source, new CategoryTrees(new StaticCategoryTreeSource()))))->onPostWrite($event);

        static::assertSame([$page => [Ids::DE]], $source->addedOwners);
    }

    /**
     * @param list<WriteCommand> $commands
     *
     * @return list<string>
     */
    private function denied(array $commands, ?Context $context = null): array
    {
        $event = new PreWriteValidationEvent(WriteContext::createFromContext($context ?? Ids::context()), $commands);
        (new CmsGuardSubscriber(Ids::scope(), new CmsPages(new Cms(), new CategoryTrees(new StaticCategoryTreeSource()))))->onPreWrite($event);

        $entities = [];
        foreach ($event->getExceptions()->getExceptions() as $exception) {
            static::assertInstanceOf(ChannelScopeException::class, $exception);
            $entities[] = $exception->getParameters()['entity'];
        }

        return $entities;
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function definition(string $class): EntityDefinition
    {
        static $registry = null;
        $registry ??= new StaticDefinitionInstanceRegistry(
            [CmsPageDefinition::class, CmsPageTranslationDefinition::class, CmsSectionDefinition::class, CmsBlockDefinition::class, CmsSlotDefinition::class, CategoryDefinition::class],
            Validation::createValidator(),
            $this->createMock(EntityWriteGatewayInterface::class),
        );

        return $registry->get($class);
    }

    /**
     * @param class-string<EntityDefinition> $class
     * @param array<string, mixed> $payload
     */
    private function insert(string $class, string $id, array $payload): InsertCommand
    {
        $definition = $this->definition($class);

        return new InsertCommand($definition, $payload, $this->key($id), new EntityExistence($definition->getEntityName(), [], false, false, false, []), '');
    }

    /**
     * @param class-string<EntityDefinition> $class
     * @param array<string, mixed> $payload
     * @param array<string, string>|null $hexKey
     */
    private function update(string $class, ?string $id, array $payload, ?array $hexKey = null): UpdateCommand
    {
        $definition = $this->definition($class);
        $key = $hexKey === null ? $this->key((string) $id) : array_map(static fn (string $value) => Uuid::fromHexToBytes($value), $hexKey);

        return new UpdateCommand($definition, $payload, $key, new EntityExistence($definition->getEntityName(), [], true, false, false, []), '');
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function delete(string $class, string $id): DeleteCommand
    {
        $definition = $this->definition($class);

        return new DeleteCommand($definition, $this->key($id), new EntityExistence($definition->getEntityName(), [], true, false, false, []));
    }

    /**
     * @return array<string, string>
     */
    private function key(string $id): array
    {
        return ['id' => Uuid::fromHexToBytes($id), 'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
    }
}
