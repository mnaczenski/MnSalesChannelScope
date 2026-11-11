<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Rule;

use Mn\SalesChannelScope\Rule\ChannelColumnLookup;
use Mn\SalesChannelScope\Rule\ParentChannelRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(ParentChannelRule::class)]
class ParentChannelRuleTest extends TestCase
{
    public const OWN_WISHLIST = '0190cccccccccccccccccccccccccc01';
    public const FOREIGN_WISHLIST = '0190cccccccccccccccccccccccccc02';
    public const OWN_ITEM = '0190dddddddddddddddddddddddddd01';
    public const FOREIGN_ITEM = '0190dddddddddddddddddddddddddd02';

    public function testRecordsAreReadThroughTheirParent(): void
    {
        static::assertEquals(new EqualsAnyFilter('wishlist.salesChannelId', [Ids::DE]), $this->rule()->readFilter('customer_wishlist_product', [Ids::DE]));
        static::assertNull($this->rule()->readFilter('customer_wishlist', [Ids::DE]));
    }

    public function testOnlyRecordsOfOwnParentsChange(): void
    {
        static::assertNull($this->deny($this->command(DeleteCommand::class, self::OWN_ITEM)));
        static::assertNotNull($this->deny($this->command(DeleteCommand::class, self::FOREIGN_ITEM)));
        static::assertNotNull($this->deny($this->command(DeleteCommand::class, Uuid::randomHex())));
        static::assertNull($this->deny($this->command(InsertCommand::class, Uuid::randomHex(), ['customer_wishlist_id' => Uuid::fromHexToBytes(self::OWN_WISHLIST)])));
        static::assertNotNull($this->deny($this->command(InsertCommand::class, Uuid::randomHex(), ['customer_wishlist_id' => Uuid::fromHexToBytes(self::FOREIGN_WISHLIST)])));
        static::assertNotNull($this->deny($this->command(InsertCommand::class, Uuid::randomHex())));
    }

    private function rule(): ParentChannelRule
    {
        $lookup = new class implements ChannelColumnLookup {
            public function channelOf(string $table, string $column, array $primaryKey): ?string
            {
                $id = Uuid::fromBytesToHex($primaryKey['id']);

                return match ($table) {
                    'customer_wishlist' => [ParentChannelRuleTest::OWN_WISHLIST => Ids::DE, ParentChannelRuleTest::FOREIGN_WISHLIST => Ids::EN][$id] ?? null,
                    default => [ParentChannelRuleTest::OWN_ITEM => ParentChannelRuleTest::OWN_WISHLIST, ParentChannelRuleTest::FOREIGN_ITEM => ParentChannelRuleTest::FOREIGN_WISHLIST][$id] ?? null,
                };
            }
        };

        return new ParentChannelRule('customer_wishlist_product', 'wishlist', 'customer_wishlist_id', 'customer_wishlist', $lookup);
    }

    private function deny(WriteCommand $command): ?string
    {
        return $this->rule()->deny($command, new WriteScope([Ids::DE], [$command], Ids::context()));
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, mixed> $payload
     */
    private function command(string $class, string $id, array $payload = []): WriteCommand
    {
        $command = $this->createMock($class);
        $command->method('getEntityName')->willReturn('customer_wishlist_product');
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::fromHexToBytes($id)]);
        $command->method('getPayload')->willReturn($payload);

        return $command;
    }
}
