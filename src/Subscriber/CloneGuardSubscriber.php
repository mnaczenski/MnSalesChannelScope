<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * POST /api/_action/clone/{entity}/{id}: Shopware reads the source record
 * without the search events the read filter hooks into, and writes the copy
 * in the system scope, which the write guard doesn't check. For a limited user:
 *
 * - the source must be visible to them (read through the repository);
 * - products only when sold in their channels only (the copy keeps the
 *   visibilities);
 * - a copy of a Shopping Experience layout belongs to their channels.
 */
class CloneGuardSubscriber implements EventSubscriberInterface
{
    public const ROUTE = 'api.clone';

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly DefinitionInstanceRegistry $registry,
        private readonly ScopeLookup $lookup,
        private readonly CmsPages $pages,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        [$context, $ids, $entity, $id] = $this->clone($event->getRequest());
        if ($context === null || $ids === null) {
            return;
        }

        if (!$this->registry->has($entity)) {
            return;
        }
        $visible = $this->registry->getRepository($entity)->searchIds(new Criteria([$id]), $context)->getTotal() > 0;
        if (!$visible) {
            throw ChannelScopeException::writeDenied($entity, 'the record belongs to another sales channel');
        }

        if ($entity === 'product') {
            $channels = $this->lookup->productChannels(Uuid::fromHexToBytes($id));
            if ($channels === null || $channels === [] || array_diff($channels, $ids) !== []) {
                throw ChannelScopeException::writeDenied($entity, 'a copy of a product that is also sold in other sales channels would be sold there too');
            }
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        [$context, $ids, $entity] = $this->clone($event->getRequest());
        if ($context === null || $ids === null || $ids === [] || $entity !== 'cms_page' || $event->getResponse()->getStatusCode() !== 200) {
            return;
        }

        $data = json_decode((string) $event->getResponse()->getContent(), true);
        $newId = \is_array($data) ? ($data['id'] ?? null) : null;
        if (\is_string($newId) && Uuid::isValid($newId)) {
            $this->pages->addOwners($newId, $ids);
        }
    }

    /**
     * @return array{?Context, ?list<string>, string, string}
     */
    private function clone(Request $request): array
    {
        if ($request->attributes->get('_route') !== self::ROUTE) {
            return [null, null, '', ''];
        }
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if (!$context instanceof Context) {
            return [null, null, '', ''];
        }

        return [
            $context,
            $this->scope->salesChannelIds($context),
            str_replace('-', '_', (string) $request->attributes->get('entity')),
            strtolower((string) $request->attributes->get('id')),
        ];
    }
}
