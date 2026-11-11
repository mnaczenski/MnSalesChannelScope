<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Subscriber\StateHistoryGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(StateHistoryGuardSubscriber::class)]
class StateHistoryGuardSubscriberTest extends TestCase
{
    private const ORDER_DE = '0190cccccccccccccccccccccccccc01';
    private const ORDER_EN = '0190cccccccccccccccccccccccccc02';
    private const TRANSACTION_DE = '0190dddddddddddddddddddddddddd01';
    private const DELIVERY_EN = '0190dddddddddddddddddddddddddd02';

    public function testOnlyTransitionsOfOwnOrdersAreFound(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('state_machine_history.referencedId', [self::ORDER_DE, self::TRANSACTION_DE, self::ORDER_EN, self::DELIVERY_EN]));
        $criteria->addFilter(new EqualsAnyFilter('state_machine_history.entityName', ['order', 'order_transaction', 'order_delivery']));

        static::assertEquals(new EqualsAnyFilter('referencedId', [self::ORDER_DE, self::TRANSACTION_DE]), $this->search($criteria));
    }

    public function testSearchesWithoutOwnRecordsFindNothing(): void
    {
        static::assertEquals(new EqualsAnyFilter('referencedId', ['00000000000000000000000000000000']), $this->search(new Criteria()));

        $foreign = new Criteria();
        $foreign->addFilter(new EqualsFilter('referencedId', self::ORDER_EN));
        static::assertEquals(new EqualsAnyFilter('referencedId', ['00000000000000000000000000000000']), $this->search($foreign));

        $either = new Criteria();
        $either->addFilter(new OrFilter([new EqualsFilter('referencedId', self::ORDER_DE), new EqualsFilter('userId', Ids::DE_USER)]));
        static::assertEquals(new EqualsAnyFilter('referencedId', ['00000000000000000000000000000000']), $this->search($either), 'an OR group does not narrow the search');
    }

    public function testUnrestrictedUsersAndOtherEntitiesAreNotFiltered(): void
    {
        static::assertNull($this->search(new Criteria(), Ids::context(Ids::FREE_USER)));
        static::assertNull($this->search(new Criteria(), entity: 'order'));
    }

    public function testNewTransitionsOnlyForOwnOrders(): void
    {
        static::assertNull($this->deny($this->command(InsertCommand::class, self::TRANSACTION_DE)));
        static::assertNotNull($this->deny($this->command(InsertCommand::class, self::DELIVERY_EN)));
        static::assertNotNull($this->deny($this->command(UpdateCommand::class, self::ORDER_DE)), 'history is never changed');
    }

    private function guard(): StateHistoryGuardSubscriber
    {
        return new StateHistoryGuardSubscriber(Ids::scope(), new StaticScopeLookup(
            orders: [self::ORDER_DE => Ids::DE, self::ORDER_EN => Ids::EN],
            children: [self::TRANSACTION_DE => self::ORDER_DE, self::DELIVERY_EN => self::ORDER_EN],
        ));
    }

    private function search(Criteria $criteria, ?Context $context = null, string $entity = StateHistoryGuardSubscriber::ENTITY): ?object
    {
        $before = \count($criteria->getFilters());
        $definition = $this->createMock(EntityDefinition::class);
        $definition->method('getEntityName')->willReturn($entity);
        $this->guard()->onSearch(new EntitySearchedEvent($criteria, $definition, $context ?? Ids::context()));
        $filters = $criteria->getFilters();

        return \count($filters) > $before ? end($filters) : null;
    }

    private function deny(WriteCommand $command): ?string
    {
        return $this->guard()->deny($command, new WriteScope([Ids::DE], [$command], Ids::context()));
    }

    /**
     * @param class-string<WriteCommand> $class
     */
    private function command(string $class, string $referencedId): WriteCommand
    {
        $command = $this->createMock($class);
        $command->method('getEntityName')->willReturn(StateHistoryGuardSubscriber::ENTITY);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::randomBytes()]);
        $command->method('getPayload')->willReturn(['referenced_id' => Uuid::fromHexToBytes($referencedId)]);

        return $command;
    }
}
