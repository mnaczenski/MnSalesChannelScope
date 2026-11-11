<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\CloneGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource;
use Mn\SalesChannelScope\Tests\StaticCmsUsageSource;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(CloneGuardSubscriber::class)]
class CloneGuardSubscriberTest extends TestCase
{
    private const VISIBLE = '0190eeeeeeeeeeeeeeeeeeeeeeee0001';
    private const HIDDEN = '0190eeeeeeeeeeeeeeeeeeeeeeee0002';
    private const PRODUCT_DE = '0190777777777777777777777777aa01';
    private const PRODUCT_SHARED = '0190777777777777777777777777aa02';

    private StaticCmsUsageSource $cms;

    public function testVisibleRecordsCanBeCloned(): void
    {
        static::assertNull($this->deny('cms-page', self::VISIBLE));
        static::assertNull($this->deny('product', self::PRODUCT_DE));
    }

    public function testHiddenRecordsAndSharedProductsCanNotBeCloned(): void
    {
        static::assertNotNull($this->deny('cms-page', self::HIDDEN));
        static::assertNotNull($this->deny('order', self::HIDDEN));
        static::assertNotNull($this->deny('product', self::PRODUCT_SHARED));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertNull($this->deny('cms-page', self::HIDDEN, Ids::context(Ids::FREE_USER)));
    }

    public function testCopiesOfLayoutsBelongToTheUsersChannels(): void
    {
        $request = $this->request('cms-page', self::VISIBLE, Ids::context());
        $response = new JsonResponse(['id' => '0190eeeeeeeeeeeeeeeeeeeeeeee0099']);
        $this->subscriber()->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        static::assertSame(['0190eeeeeeeeeeeeeeeeeeeeeeee0099' => [Ids::DE]], $this->cms->addedOwners);
    }

    private function deny(string $entity, string $id, ?Context $context = null): ?string
    {
        $request = $this->request($entity, $id, $context ?? Ids::context());
        try {
            $this->subscriber()->onController(new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ChannelScopeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function request(string $entity, string $id, Context $context): Request
    {
        $request = new Request();
        $request->attributes->add(['_route' => CloneGuardSubscriber::ROUTE, 'entity' => $entity, 'id' => $id, PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT => $context]);

        return $request;
    }

    private function subscriber(): CloneGuardSubscriber
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(static function (Criteria $criteria, Context $context): IdSearchResult {
            $found = [];
            foreach ($criteria->getIds() as $id) {
                if (\is_string($id) && $id !== self::HIDDEN) {
                    $found[$id] = ['primaryKey' => $id, 'data' => []];
                }
            }

            return new IdSearchResult(\count($found), $found, $criteria, $context);
        });
        $registry = $this->createMock(DefinitionInstanceRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('getRepository')->willReturn($repository);

        $this->cms ??= new StaticCmsUsageSource();

        return new CloneGuardSubscriber(
            Ids::scope(),
            $registry,
            new StaticScopeLookup(products: [self::PRODUCT_DE => [Ids::DE], self::PRODUCT_SHARED => [Ids::DE, Ids::EN]]),
            new CmsPages($this->cms, new CategoryTrees(new StaticCategoryTreeSource())),
        );
    }
}
