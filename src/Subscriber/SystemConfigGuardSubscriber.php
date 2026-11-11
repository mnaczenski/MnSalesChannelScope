<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Scope\ConfigRules;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The settings pages read and save configuration values through
 * /api/_action/system-config, which uses SystemConfigService with plain SQL:
 * neither the read filter nor the write guard sees these requests. For a
 * restricted user:
 *
 * - reading: only readable domains (ConfigRules), for one of their sales
 *   channels or for all channels (the inherited values the pages show);
 * - saving: only editable domains, and only for one of their channels.
 */
class SystemConfigGuardSubscriber implements EventSubscriberInterface
{
    public const READ_ROUTE = 'api.action.core.system-config.value';
    public const SAVE_ROUTE = 'api.action.core.save.system-config';
    public const BATCH_ROUTE = 'api.action.core.save.system-config.batch';

    public function __construct(private readonly ChannelScope $scope)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!\in_array($route, [self::READ_ROUTE, self::SAVE_ROUTE, self::BATCH_ROUTE], true)) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if (!$context instanceof Context) {
            return;
        }
        $ids = $this->scope->salesChannelIds($context);
        if ($ids === null) {
            return;
        }
        $allowed = array_flip($ids);

        $reason = match ($route) {
            self::READ_ROUTE => $this->denyRead($request, $allowed),
            self::SAVE_ROUTE => $this->denySave($this->channel($request->query->get('salesChannelId')), $request->request->all(), $allowed),
            default => $this->denyBatch($request->request->all(), $allowed),
        };
        if ($reason !== null) {
            throw ChannelScopeException::settingsDenied($reason);
        }
    }

    /**
     * @param array<string, int> $allowed
     */
    private function denyRead(Request $request, array $allowed): ?string
    {
        if (!ConfigRules::isReadable($request->query->getString('domain'))) {
            return 'this settings domain is only available to unrestricted users';
        }
        $channel = $this->channel($request->query->get('salesChannelId'));
        if ($channel !== null && !isset($allowed[$channel])) {
            return 'the sales channel is not one of yours';
        }

        return null;
    }

    /**
     * @param array<mixed> $values
     * @param array<string, int> $allowed
     */
    private function denySave(?string $channel, array $values, array $allowed): ?string
    {
        if ($channel === null) {
            return 'settings for all sales channels can only be changed by unrestricted users';
        }
        if (!isset($allowed[$channel])) {
            return 'the sales channel is not one of yours';
        }
        foreach (array_keys($values) as $key) {
            if (!ConfigRules::isEditable((string) $key)) {
                return \sprintf('"%s" can only be changed by unrestricted users', $key);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $batch sales channel id (or "null") => values
     * @param array<string, int> $allowed
     */
    private function denyBatch(array $batch, array $allowed): ?string
    {
        foreach ($batch as $channel => $values) {
            if (!\is_array($values) || $values === []) {
                continue;
            }
            $reason = $this->denySave($this->channel($channel), $values, $allowed);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function channel(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || $value === 'null') {
            return null;
        }

        return strtolower($value);
    }
}
