<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Rule;

use Mn\SalesChannelScope\Rule\OrderDescendantRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(OrderDescendantRule::class)]
class OrderDescendantRuleTest extends TestCase
{
    private const OWN_ORDER = '0190cccccccccccccccccccccccccc01';
    private const FOREIGN_ORDER = '0190cccccccccccccccccccccccccc02';
    private const OWN_TRANSACTION = '0190dddddddddddddddddddddddddd01';
    private const FOREIGN_TRANSACTION = '0190dddddddddddddddddddddddddd02';
    private const OWN_CAPTURE = '0190eeeeeeeeeeeeeeeeeeeeeeeeee01';
    private const FOREIGN_CAPTURE = '0190eeeeeeeeeeeeeeeeeeeeeeeeee02';
    private const OWN_REFUND = '0190ffffffffffffffffffffffffff01';
    private const FOREIGN_REFUND = '0190ffffffffffffffffffffffffff02';
    private const OWN_DELIVERY = '0190abababababababababababab0001';
    private const OWN_POSITION = '0190abababababababababababab0011';

    public function testEveryDescendantIsReadThroughItsOrder(): void
    {
        $rule = $this->rule();

        static::assertEquals(new EqualsAnyFilter('orderDelivery.order.salesChannelId', [Ids::DE]), $rule->readFilter('order_delivery_position', [Ids::DE]));
        static::assertEquals(
            new EqualsAnyFilter('orderLineItem.order.salesChannelId', [Ids::DE]),
            $rule->readFilter('order_transaction_capture_refund_position', [Ids::DE]),
        );
        static::assertEquals(new EqualsAnyFilter('document.order.salesChannelId', [Ids::DE]), $rule->readFilter('document_file', [Ids::DE]));
        static::assertNull($rule->readFilter('order', [Ids::DE]));
    }

    public function testRecordsOfForeignOrdersCanNotChange(): void
    {
        static::assertNull($this->deny($this->command(UpdateCommand::class, 'order_transaction_capture_refund', self::OWN_REFUND, ['amount' => 1])));
        static::assertNotNull($this->deny($this->command(UpdateCommand::class, 'order_transaction_capture_refund', self::FOREIGN_REFUND, ['amount' => 1])));
        static::assertNotNull($this->deny($this->command(DeleteCommand::class, 'order_transaction_capture', self::FOREIGN_CAPTURE)));
        static::assertNotNull($this->deny($this->command(UpdateCommand::class, 'order_transaction_capture', Uuid::randomHex(), [])), 'unknown records are refused');
    }

    public function testRecordsCanNotBeAddedToOrMovedToForeignOrders(): void
    {
        static::assertNull($this->deny($this->command(InsertCommand::class, 'order_transaction_capture', Uuid::randomHex(), ['order_transaction_id' => Uuid::fromHexToBytes(self::OWN_TRANSACTION)])));
        static::assertNotNull($this->deny($this->command(InsertCommand::class, 'order_transaction_capture', Uuid::randomHex(), ['order_transaction_id' => Uuid::fromHexToBytes(self::FOREIGN_TRANSACTION)])));
        static::assertNotNull($this->deny($this->command(InsertCommand::class, 'order_transaction_capture', Uuid::randomHex(), [])));
        static::assertNotNull($this->deny($this->command(UpdateCommand::class, 'order_transaction_capture', self::OWN_CAPTURE, ['order_transaction_id' => Uuid::fromHexToBytes(self::FOREIGN_TRANSACTION)])));
        static::assertNull($this->deny($this->command(UpdateCommand::class, 'order_delivery_position', self::OWN_POSITION, ['quantity' => 2])));
    }

    public function testRecordsInsertedTogetherAreFollowedToTheirOrder(): void
    {
        $capture = Uuid::randomHex();
        $refund = $this->command(InsertCommand::class, 'order_transaction_capture_refund', Uuid::randomHex(), ['capture_id' => Uuid::fromHexToBytes($capture)]);

        $own = $this->command(InsertCommand::class, 'order_transaction_capture', $capture, ['order_transaction_id' => Uuid::fromHexToBytes(self::OWN_TRANSACTION)]);
        static::assertNull($this->rule()->deny($refund, new WriteScope([Ids::DE], [$own, $refund], Ids::context())));

        $foreign = $this->command(InsertCommand::class, 'order_transaction_capture', $capture, ['order_transaction_id' => Uuid::fromHexToBytes(self::FOREIGN_TRANSACTION)]);
        static::assertNotNull($this->rule()->deny($refund, new WriteScope([Ids::DE], [$foreign, $refund], Ids::context())));
    }

    public function testOtherEntitiesAreNotItsConcern(): void
    {
        static::assertNull($this->deny($this->command(UpdateCommand::class, 'order', self::FOREIGN_ORDER, [])));
    }

    private function rule(): OrderDescendantRule
    {
        return new OrderDescendantRule(new StaticScopeLookup(
            orders: [self::OWN_ORDER => Ids::DE, self::FOREIGN_ORDER => Ids::EN],
            children: [self::OWN_TRANSACTION => self::OWN_ORDER, self::FOREIGN_TRANSACTION => self::FOREIGN_ORDER, self::OWN_DELIVERY => self::OWN_ORDER],
            parents: [
                self::OWN_CAPTURE => self::OWN_TRANSACTION,
                self::FOREIGN_CAPTURE => self::FOREIGN_TRANSACTION,
                self::OWN_REFUND => self::OWN_CAPTURE,
                self::FOREIGN_REFUND => self::FOREIGN_CAPTURE,
                self::OWN_POSITION => self::OWN_DELIVERY,
            ],
        ));
    }

    private function deny(WriteCommand $command): ?string
    {
        return $this->rule()->deny($command, new WriteScope([Ids::DE], [$command], Ids::context()));
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, mixed> $payload
     */
    private function command(string $class, string $entity, string $id, array $payload = []): WriteCommand
    {
        $command = $this->createMock($class);
        $command->method('getEntityName')->willReturn($entity);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::fromHexToBytes($id)]);
        $command->method('getPayload')->willReturn($payload);

        return $command;
    }
}
