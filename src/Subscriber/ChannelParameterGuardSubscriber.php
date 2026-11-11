<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Endpoints that take a sales channel id and then work in a storefront
 * context of that channel, outside the read filter: the product stream
 * preview, the Advanced Search preview, the sales channel files (robots.txt
 * and the like). A limited user may only name their own channels.
 *
 * Switching the customer of an admin order cart (PATCH
 * /api/_proxy/switch-customer) loads the customer in the storefront context
 * too; a limited user may only pick a customer they can see.
 */
class ChannelParameterGuardSubscriber implements EventSubscriberInterface
{
    /** route => where the sales channel id is: attributes, query or request (body) */
    public const ROUTES = [
        'api.admin.product-stream-preview' => 'attributes',
        'api.action.sales_channel_file.list' => 'attributes',
        'api.action.sales_channel_file.detail' => 'attributes',
        'api.action.sales_channel_file.preview' => 'attributes',
        'api.action.preview_search.search' => 'query',
        'api.proxy.switch-customer' => 'request',
    ];

    public const SWITCH_CUSTOMER = 'api.proxy.switch-customer';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Customer\CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly ChannelScope $scope,
        private readonly EntityRepository $customerRepository,
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
        $bag = \is_string($route) ? self::ROUTES[$route] ?? null : null;
        if ($bag === null) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if (!$context instanceof Context) {
            return;
        }
        $allowed = $this->scope->salesChannelIds($context);
        if ($allowed === null) {
            return;
        }

        $salesChannelId = $this->parameter($request, $bag, 'salesChannelId');
        if (!\in_array($salesChannelId, array_map('strtolower', $allowed), true)) {
            throw ChannelScopeException::routeDenied($request->getPathInfo(), 'the sales channel is not one of yours');
        }

        if ($route === self::SWITCH_CUSTOMER) {
            $customerId = $this->parameter($request, 'request', 'customerId');
            if (!Uuid::isValid($customerId) || $this->customerRepository->searchIds(new Criteria([$customerId]), $context)->getTotal() === 0) {
                throw ChannelScopeException::routeDenied($request->getPathInfo(), 'the customer is registered in another sales channel');
            }
        }
    }

    private function parameter(Request $request, string $bag, string $name): string
    {
        $value = match ($bag) {
            'attributes' => $request->attributes->get($name),
            'query' => $request->query->get($name),
            default => $request->request->all()[$name] ?? $this->json($request)[$name] ?? null,
        };

        return \is_string($value) ? strtolower($value) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        try {
            $data = json_decode((string) $request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }
}
