<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The administration's global search with OpenSearch
 * (POST /api/_admin/es-search, SHOPWARE_ADMIN_ES_ENABLED) asks the search
 * index for the best matches of all sales channels and only then loads them
 * through the repositories. For a limited user, the read filter would drop
 * matches of other channels afterwards, hiding their own matches further
 * down, and the hit counts would come from the index, revealing other
 * channels' numbers.
 *
 * Limited users therefore use the database search (POST /api/_admin/search,
 * filtered like every repository search): the administration switches them
 * over (sw-search-bar), and this route refuses them.
 */
class AdminSearchGuardSubscriber implements EventSubscriberInterface
{
    public const ROUTE = 'api.admin.es-search';

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
        if ($request->attributes->get('_route') !== self::ROUTE) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if ($context instanceof Context && $this->scope->salesChannelIds($context) !== null) {
            throw ChannelScopeException::routeDenied('/api/_admin/es-search', 'the search index is not limited to sales channels; use /api/_admin/search');
        }
    }
}
