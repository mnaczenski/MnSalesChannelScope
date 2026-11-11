<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Rule\WriteRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs the tagged write rules (Tags::WRITE_RULE) for every write of a
 * restricted user.
 */
class WriteRuleSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<WriteRule> $rules
     */
    public function __construct(
        private readonly ChannelScope $scope,
        private readonly iterable $rules,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'onPreWrite'];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null) {
            return;
        }

        $scope = new WriteScope($ids, $event->getCommands(), $event->getContext());
        foreach ($event->getCommands() as $command) {
            foreach ($this->rules as $rule) {
                $reason = $rule->deny($command, $scope);
                if ($reason !== null) {
                    $event->getExceptions()->add(ChannelScopeException::writeDenied($command->getEntityName(), $reason));

                    break;
                }
            }
        }
    }
}
