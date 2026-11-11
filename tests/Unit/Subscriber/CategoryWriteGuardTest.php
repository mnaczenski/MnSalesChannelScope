<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\WriteGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource as T;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductCategory\ProductCategoryDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;

#[CoversClass(WriteGuardSubscriber::class)]
class CategoryWriteGuardTest extends TestCase
{
    private const PRODUCT_DE = '0190777777777777777777777777aa01';

    public function testCategoriesOfTheUsersChannelsAloneCanChange(): void
    {
        static::assertSame([], $this->denied([$this->update(T::DE_SHOES, ['active' => 1])]));
        static::assertSame(['category'], $this->denied([$this->update(T::SHARED_SALE, ['active' => 1])]), 'shared trees are read-only');
        static::assertSame(['category'], $this->denied([$this->update(T::ROOT, ['active' => 1])]), 'ancestors are read-only');
        static::assertSame(['category'], $this->denied([$this->update(T::EN_SOFAS, ['active' => 1])]));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertSame([], $this->denied([$this->update(T::SHARED_SALE, ['active' => 1])], Ids::context(Ids::FREE_USER)));
    }

    public function testNewCategoriesNeedAnEditableParent(): void
    {
        $id = Uuid::randomHex();
        static::assertSame([], $this->denied([$this->insert(CategoryDefinition::class, $id, ['parent_id' => Uuid::fromHexToBytes(T::DE_NAV)])]));
        static::assertSame(['category'], $this->denied([$this->insert(CategoryDefinition::class, $id, ['parent_id' => Uuid::fromHexToBytes(T::SHARED)])]));
        static::assertSame(['category'], $this->denied([$this->insert(CategoryDefinition::class, $id, [])]), 'no root categories');

        $translation = new InsertCommand($this->definition(CategoryTranslationDefinition::class), ['category_id' => Uuid::fromHexToBytes($id)], ['category_id' => Uuid::fromHexToBytes($id), 'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)], $this->existence(false), '');
        static::assertSame([], $this->denied([$this->insert(CategoryDefinition::class, $id, ['parent_id' => Uuid::fromHexToBytes(T::DE_NAV)]), $translation]), 'with its translation');
    }

    public function testTranslationsOfSharedCategoriesCanNotChange(): void
    {
        $translation = new UpdateCommand($this->definition(CategoryTranslationDefinition::class), ['name' => 'x'], ['category_id' => Uuid::fromHexToBytes(T::SHARED), 'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)], $this->existence(true), '');

        static::assertSame(['category_translation'], $this->denied([$translation]));
    }

    public function testCategoriesMoveOnlyBelowEditableCategories(): void
    {
        static::assertSame(['category'], $this->denied([$this->update(T::DE_SHOES, ['parent_id' => Uuid::fromHexToBytes(T::SHARED)])]));
        static::assertSame([], $this->denied([$this->update(T::DE_SHOES, ['parent_id' => Uuid::fromHexToBytes(T::DE_NAV)])]));
        static::assertSame(['category'], $this->denied([new DeleteCommand($this->definition(CategoryDefinition::class), $this->key(T::ROOT), $this->existence(true))]));
    }

    public function testProductsAreAssignedOnlyToVisibleCategories(): void
    {
        $assign = fn (string $category) => new InsertCommand(
            $this->definition(ProductCategoryDefinition::class),
            ['product_id' => Uuid::fromHexToBytes(self::PRODUCT_DE), 'category_id' => Uuid::fromHexToBytes($category)],
            ['product_id' => Uuid::fromHexToBytes(self::PRODUCT_DE), 'category_id' => Uuid::fromHexToBytes($category)],
            $this->existence(false),
            '',
        );

        static::assertSame([], $this->denied([$assign(T::SHARED_SALE)]), 'shared but visible');
        static::assertSame(['product_category'], $this->denied([$assign(T::EN_SOFAS)]));
        static::assertSame(['product_category'], $this->denied([$assign(T::ORPHAN)]));
    }

    /**
     * @param list<WriteCommand> $commands
     *
     * @return list<string>
     */
    private function denied(array $commands, ?Context $context = null): array
    {
        $lookup = new StaticScopeLookup(products: [self::PRODUCT_DE => [Ids::DE]]);
        $event = new PreWriteValidationEvent(WriteContext::createFromContext($context ?? Ids::context()), $commands);
        (new WriteGuardSubscriber(Ids::scope(), $lookup, T::trees()))->onPreWrite($event);

        $entities = [];
        foreach ($event->getExceptions()->getExceptions() as $exception) {
            static::assertInstanceOf(ChannelScopeException::class, $exception);
            $entities[] = $exception->getParameters()['entity'];
        }

        return $entities;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(string $id, array $payload): UpdateCommand
    {
        return new UpdateCommand($this->definition(CategoryDefinition::class), $payload, $this->key($id), $this->existence(true), '');
    }

    /**
     * @param class-string<EntityDefinition> $class
     * @param array<string, mixed> $payload
     */
    private function insert(string $class, string $id, array $payload): InsertCommand
    {
        return new InsertCommand($this->definition($class), $payload, $this->key($id), $this->existence(false), '');
    }

    /**
     * @return array<string, string>
     */
    private function key(string $id): array
    {
        return ['id' => Uuid::fromHexToBytes($id), 'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
    }

    private function existence(bool $exists): EntityExistence
    {
        return new EntityExistence('category', [], $exists, false, false, []);
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function definition(string $class): EntityDefinition
    {
        static $registry = null;
        $registry ??= new StaticDefinitionInstanceRegistry(
            [CategoryDefinition::class, CategoryTranslationDefinition::class, ProductCategoryDefinition::class],
            Validation::createValidator(),
            $this->createMock(EntityWriteGatewayInterface::class),
        );

        return $registry->get($class);
    }
}
