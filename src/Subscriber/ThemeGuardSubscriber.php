<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Theme actions of the administration that don't go (only) through the write
 * guard. For a limited user:
 *
 * - assigning a theme (POST /api/_action/theme/{themeId}/assign/{salesChannelId}):
 *   Shopware marks the pending theme with plain SQL and may switch the theme
 *   after an asynchronous compilation; only for their channels;
 * - changing or resetting a theme's configuration (PATCH /api/_action/theme/{themeId},
 *   /reset): themes are shared; only a theme used by their channels only.
 */
class ThemeGuardSubscriber implements EventSubscriberInterface
{
    public const ASSIGN_ROUTE = 'api.action.theme.assign';
    public const CONFIG_ROUTES = ['api.action.theme.update', 'api.action.theme.reset'];

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ScopeLookup $lookup,
    ) {
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
        if ($route !== self::ASSIGN_ROUTE && !\in_array($route, self::CONFIG_ROUTES, true)) {
            return;
        }
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        $ids = $context instanceof Context ? $this->scope->salesChannelIds($context) : null;
        if ($ids === null) {
            return;
        }

        if ($route === self::ASSIGN_ROUTE) {
            if (!\in_array(strtolower((string) $request->attributes->get('salesChannelId')), $ids, true)) {
                throw ChannelScopeException::writeDenied('theme_sales_channel', 'the sales channel is not one of yours');
            }

            return;
        }

        $themeId = (string) $request->attributes->get('themeId');
        $channels = Uuid::isValid($themeId) ? $this->lookup->themeChannels(Uuid::fromHexToBytes($themeId)) : [];
        if ($channels === [] || array_diff($channels, $ids) !== []) {
            throw ChannelScopeException::writeDenied('theme', 'the theme is also used by other sales channels');
        }
    }
}
