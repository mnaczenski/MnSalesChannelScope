<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Rule;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Rule\WriteRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Subscriber\WriteRuleSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;

#[CoversClass(WriteRuleSubscriber::class)]
#[CoversClass(WriteScope::class)]
class WriteRuleSubscriberTest extends TestCase
{
    public function testTheFirstReasonRefusesTheCommand(): void
    {
        $event = $this->event([$this->insert(), $this->insert()]);
        $calls = 0;
        $rules = [
            $this->rule(static function () use (&$calls): string {
                ++$calls;

                return 'no';
            }),
            $this->rule(static fn () => static::fail('later rules are skipped once a command is refused')),
        ];

        (new WriteRuleSubscriber(Ids::scope(), $rules))->onPreWrite($event);

        static::assertSame(2, $calls);
        static::assertCount(2, $event->getExceptions()->getExceptions());
        static::assertInstanceOf(ChannelScopeException::class, $event->getExceptions()->getExceptions()[0]);
    }

    public function testRulesSeeTheWholeWrite(): void
    {
        $first = $this->insert();
        $seen = null;
        $rule = $this->rule(static function (WriteCommand $command, WriteScope $scope) use (&$seen): ?string {
            $seen = $scope;

            return null;
        });

        $event = $this->event([$first]);
        (new WriteRuleSubscriber(Ids::scope(), [$rule]))->onPreWrite($event);

        static::assertInstanceOf(WriteScope::class, $seen);
        static::assertSame([Ids::DE], $seen->salesChannelIds());
        static::assertSame($first, $seen->insertOf('newsletter_recipient', Uuid::fromBytesToHex($first->getPrimaryKey()['id'])));
        static::assertTrue($seen->isOwn(Uuid::fromHexToBytes(Ids::DE)));
        static::assertTrue($seen->ownsAll([Ids::DE]));
        static::assertFalse($seen->ownsAll([Ids::DE, Ids::EN]));
        static::assertFalse($seen->ownsAll([]));
        static::assertSame([], $event->getExceptions()->getExceptions());
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        $event = $this->event([$this->insert()], Ids::context(Ids::FREE_USER));
        (new WriteRuleSubscriber(Ids::scope(), [$this->rule(static fn () => 'no')]))->onPreWrite($event);

        static::assertSame([], $event->getExceptions()->getExceptions());
    }

    private function rule(\Closure $deny): WriteRule
    {
        return new class($deny) implements WriteRule {
            public function __construct(private readonly \Closure $deny)
            {
            }

            public function deny(WriteCommand $command, WriteScope $scope): ?string
            {
                return ($this->deny)($command, $scope);
            }
        };
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function event(array $commands, ?Context $context = null): PreWriteValidationEvent
    {
        return new PreWriteValidationEvent(WriteContext::createFromContext($context ?? Ids::context()), $commands);
    }

    private function insert(): InsertCommand
    {
        $registry = new StaticDefinitionInstanceRegistry([NewsletterRecipientDefinition::class], Validation::createValidator(), $this->createMock(EntityWriteGatewayInterface::class));

        return new InsertCommand($registry->get(NewsletterRecipientDefinition::class), [], ['id' => Uuid::randomBytes()], new EntityExistence('newsletter_recipient', [], false, false, false, []), '');
    }
}
