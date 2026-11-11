<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Rule;

use Mn\SalesChannelScope\Rule\ChannelColumnLookup;
use Mn\SalesChannelScope\Rule\SalesChannelColumnRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;

#[CoversClass(SalesChannelColumnRule::class)]
#[CoversClass(WriteScope::class)]
class SalesChannelColumnRuleTest extends TestCase
{
    private const OWN = '0190f0f0f0f0f0f0f0f0f0f0f0f00001';
    private const FOREIGN = '0190f0f0f0f0f0f0f0f0f0f0f0f00002';
    private const ALL = '0190f0f0f0f0f0f0f0f0f0f0f0f00003';

    public function testReadsAreLimitedToTheUsersChannels(): void
    {
        static::assertEquals(new EqualsAnyFilter('salesChannelId', [Ids::DE]), $this->rule()->readFilter('newsletter_recipient', [Ids::DE]));
        static::assertNull($this->rule()->readFilter('customer', [Ids::DE]));
        static::assertEquals(
            new OrFilter([new EqualsAnyFilter('salesChannelId', [Ids::DE]), new EqualsFilter('salesChannelId', null)]),
            $this->rule(true)->readFilter('newsletter_recipient', [Ids::DE]),
        );
    }

    public function testOnlyRecordsOfTheUsersChannelsChange(): void
    {
        static::assertNull($this->deny($this->update(self::OWN, ['status' => 'optIn'])));
        static::assertNotNull($this->deny($this->update(self::FOREIGN, ['status' => 'optIn'])));
        static::assertNotNull($this->deny($this->delete(self::FOREIGN)));
        static::assertNotNull($this->deny($this->update('0190f0f0f0f0f0f0f0f0f0f0f0f09999', [])), 'unknown records are refused');
    }

    public function testRecordsCanNotMoveOrBeCreatedElsewhere(): void
    {
        static::assertNotNull($this->deny($this->update(self::OWN, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)])));
        static::assertNull($this->deny($this->insert(['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)])));
        static::assertNotNull($this->deny($this->insert(['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)])));
        static::assertNotNull($this->deny($this->insert([])));
    }

    public function testRecordsForAllChannelsAreOnlyVisible(): void
    {
        static::assertSame('it applies to all sales channels', $this->deny($this->update(self::ALL, ['status' => 'x']), true));
    }

    public function testOtherEntitiesAreNotItsConcern(): void
    {
        $command = $this->createMock(WriteCommand::class);
        $command->method('getEntityName')->willReturn('customer');

        static::assertNull($this->deny($command));
    }

    private function rule(bool $allowAllChannels = false): SalesChannelColumnRule
    {
        $lookup = new class implements ChannelColumnLookup {
            public function channelOf(string $table, string $column, array $primaryKey): ?string
            {
                return [
                    SalesChannelColumnRuleTest::own() => Ids::DE,
                    SalesChannelColumnRuleTest::foreign() => Ids::EN,
                    SalesChannelColumnRuleTest::all() => '',
                ][Uuid::fromBytesToHex($primaryKey['id'])] ?? null;
            }
        };

        return new SalesChannelColumnRule('newsletter_recipient', $lookup, allowAllChannels: $allowAllChannels);
    }

    public static function own(): string
    {
        return self::OWN;
    }

    public static function foreign(): string
    {
        return self::FOREIGN;
    }

    public static function all(): string
    {
        return self::ALL;
    }

    private function deny(WriteCommand $command, bool $allowAllChannels = false): ?string
    {
        return $this->rule($allowAllChannels)->deny($command, new WriteScope([Ids::DE], [$command], Ids::context()));
    }

    private function definition(): EntityDefinition
    {
        static $registry = null;
        $registry ??= new StaticDefinitionInstanceRegistry([NewsletterRecipientDefinition::class], Validation::createValidator(), $this->createMock(EntityWriteGatewayInterface::class));

        return $registry->get(NewsletterRecipientDefinition::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insert(array $payload): InsertCommand
    {
        return new InsertCommand($this->definition(), $payload, ['id' => Uuid::randomBytes()], new EntityExistence('newsletter_recipient', [], false, false, false, []), '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(string $id, array $payload): UpdateCommand
    {
        return new UpdateCommand($this->definition(), $payload, ['id' => Uuid::fromHexToBytes($id)], new EntityExistence('newsletter_recipient', [], true, false, false, []), '');
    }

    private function delete(string $id): DeleteCommand
    {
        return new DeleteCommand($this->definition(), ['id' => Uuid::fromHexToBytes($id)], new EntityExistence('newsletter_recipient', [], true, false, false, []));
    }
}
