<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Log in as customer" (POST /api/_proxy/generate-imitate-customer-token):
 * core checks that the customer and the sales channel exist, without the
 * search events the read filter hooks into. A restricted user may only log in
 * to one of their sales channels, as a customer they may edit (registered in
 * or bound to one of their channels): a customer who only ordered there would
 * show their orders of other channels in the storefront account.
 */
class ImitateCustomerGuardSubscriber implements EventSubscriberInterface
{
    public const VALIDATION_EVENT = 'framework.validation.impersonation.generate-token';

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ScopeLookup $lookup,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::VALIDATION_EVENT => 'onBuildValidation'];
    }

    public function onBuildValidation(BuildValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null) {
            return;
        }
        $allowed = array_flip($ids);

        $event->getDefinition()->add('salesChannelId', new Callback(
            static function (mixed $value, ExecutionContextInterface $context) use ($allowed): void {
                if (!\is_string($value) || !isset($allowed[strtolower($value)])) {
                    $context->addViolation('The sales channel is not one of yours.');
                }
            }
        ));

        $event->getDefinition()->add('customerId', new Callback(
            function (mixed $value, ExecutionContextInterface $context) use ($allowed): void {
                $customer = \is_string($value) && Uuid::isValid($value)
                    ? $this->lookup->customerChannels(Uuid::fromHexToBytes(strtolower($value)))
                    : null;
                $owned = $customer !== null && (
                    isset($allowed[$customer['salesChannelId']])
                    || ($customer['boundSalesChannelId'] !== null && isset($allowed[$customer['boundSalesChannelId']]))
                );
                if (!$owned) {
                    $context->addViolation('The customer is registered in another sales channel.');
                }
            }
        ));
    }
}
