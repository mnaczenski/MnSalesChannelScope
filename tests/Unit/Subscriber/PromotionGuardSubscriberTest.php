<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\PromotionGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticPromotionSource as P;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountDefinition;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscountPrice\PromotionDiscountPriceDefinition;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionSalesChannel\PromotionSalesChannelDefinition;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionTranslation\PromotionTranslationDefinition;
use Shopware\Core\Checkout\Promotion\PromotionDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
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
use Shopware\Core\PlatformRequest;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\Validation;

#[CoversClass(PromotionGuardSubscriber::class)]
class PromotionGuardSubscriberTest extends TestCase
{
    public function testOwnPromotionsAndTheirPartsCanChange(): void
    {
        static::assertSame([], $this->denied([
            $this->update(PromotionDefinition::class, P::PROMO_DE, ['active' => 1]),
            $this->update(PromotionDiscountDefinition::class, P::DISCOUNT_DE, ['value' => 10]),
            $this->insert(PromotionDiscountPriceDefinition::class, Uuid::randomHex(), ['discount_id' => $this->b(P::DISCOUNT_DE)]),
            $this->update(PromotionTranslationDefinition::class, null, ['name' => 'x'], ['promotion_id' => $this->b(P::PROMO_DE), 'language_id' => $this->b(Defaults::LANGUAGE_SYSTEM)]),
        ]));
    }

    public function testSharedAndForeignPromotionsAreReadOnly(): void
    {
        static::assertSame(['promotion'], $this->denied([$this->update(PromotionDefinition::class, P::PROMO_SHARED, ['active' => 1])]));
        static::assertSame(['promotion'], $this->denied([$this->delete(PromotionDefinition::class, P::PROMO_EN)]));
        static::assertSame(['promotion_discount'], $this->denied([$this->update(PromotionDiscountDefinition::class, P::DISCOUNT_SHARED, ['value' => 10])]));
        static::assertSame(['promotion_discount_prices'], $this->denied([$this->insert(PromotionDiscountPriceDefinition::class, Uuid::randomHex(), ['discount_id' => $this->b(P::DISCOUNT_SHARED)])]));
        static::assertSame(['promotion_discount'], $this->denied([$this->insert(PromotionDiscountDefinition::class, Uuid::randomHex(), ['promotion_id' => $this->b(P::PROMO_SHARED)])]));
    }

    public function testNewPromotionsNeedOnlyTheUsersChannels(): void
    {
        $id = Uuid::randomHex();
        $assign = fn (string $channel) => $this->insert(PromotionSalesChannelDefinition::class, Uuid::randomHex(), ['promotion_id' => $this->b($id), 'sales_channel_id' => $this->b($channel)]);
        $discount = Uuid::randomHex();

        static::assertSame([], $this->denied([
            $this->insert(PromotionDefinition::class, $id, ['active' => 1]),
            $assign(Ids::DE),
            $this->insert(PromotionDiscountDefinition::class, $discount, ['promotion_id' => $this->b($id)]),
            $this->insert(PromotionDiscountPriceDefinition::class, Uuid::randomHex(), ['discount_id' => $this->b($discount)]),
        ]));
        static::assertSame(['promotion'], $this->denied([$this->insert(PromotionDefinition::class, $id, ['active' => 1])]));
        static::assertSame(['promotion_sales_channel'], $this->denied([$this->insert(PromotionDefinition::class, $id, ['active' => 1]), $assign(Ids::DE), $assign(Ids::EN)]));
    }

    public function testAssignmentsOnlyForTheUsersChannels(): void
    {
        static::assertSame(['promotion_sales_channel'], $this->denied([$this->insert(PromotionSalesChannelDefinition::class, Uuid::randomHex(), ['promotion_id' => $this->b(P::PROMO_DE), 'sales_channel_id' => $this->b(Ids::EN)])]));
        static::assertSame(['promotion_sales_channel'], $this->denied([$this->delete(PromotionSalesChannelDefinition::class, P::ASSIGNMENT_SHARED_EN)]));
        static::assertSame([], $this->denied([$this->delete(PromotionSalesChannelDefinition::class, P::ASSIGNMENT_DE)]));
    }

    public function testCodesOnlyForOwnPromotions(): void
    {
        static::assertNull($this->denyRoute(P::PROMO_DE));
        static::assertNotNull($this->denyRoute(P::PROMO_SHARED));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertSame([], $this->denied([$this->delete(PromotionDefinition::class, P::PROMO_EN)], Ids::context(Ids::FREE_USER)));
    }

    private function denyRoute(string $promotionId): ?string
    {
        $request = new Request([], ['promotionId' => $promotionId]);
        $request->attributes->add(['_route' => PromotionGuardSubscriber::CODE_ROUTES[0], PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT => Ids::context()]);
        try {
            (new PromotionGuardSubscriber(Ids::scope(), new P()))->onController(new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ChannelScopeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param list<WriteCommand> $commands
     *
     * @return list<string>
     */
    private function denied(array $commands, ?Context $context = null): array
    {
        $event = new PreWriteValidationEvent(WriteContext::createFromContext($context ?? Ids::context()), $commands);
        (new PromotionGuardSubscriber(Ids::scope(), new P()))->onPreWrite($event);

        $entities = [];
        foreach ($event->getExceptions()->getExceptions() as $exception) {
            static::assertInstanceOf(ChannelScopeException::class, $exception);
            $entities[] = $exception->getParameters()['entity'];
        }

        return $entities;
    }

    private function b(string $hex): string
    {
        return Uuid::fromHexToBytes($hex);
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function definition(string $class): EntityDefinition
    {
        static $registry = null;
        $registry ??= new StaticDefinitionInstanceRegistry(
            [PromotionDefinition::class, PromotionDiscountDefinition::class, PromotionDiscountPriceDefinition::class, PromotionSalesChannelDefinition::class, PromotionTranslationDefinition::class],
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
        $d = $this->definition($class);

        return new InsertCommand($d, $payload, ['id' => $this->b($id)], new EntityExistence($d->getEntityName(), [], false, false, false, []), '');
    }

    /**
     * @param class-string<EntityDefinition> $class
     * @param array<string, mixed> $payload
     * @param array<string, string>|null $key
     */
    private function update(string $class, ?string $id, array $payload, ?array $key = null): UpdateCommand
    {
        $d = $this->definition($class);

        return new UpdateCommand($d, $payload, $key ?? ['id' => $this->b((string) $id)], new EntityExistence($d->getEntityName(), [], true, false, false, []), '');
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function delete(string $class, string $id): DeleteCommand
    {
        $d = $this->definition($class);

        return new DeleteCommand($d, ['id' => $this->b($id)], new EntityExistence($d->getEntityName(), [], true, false, false, []));
    }
}
