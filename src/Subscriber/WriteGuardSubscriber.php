<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses writes of a restricted admin user that touch records outside
 * their sales channels, before and after the change:
 *
 * - orders and their details: the order's channel must be theirs, and an
 *   order can't move to another channel;
 * - customers and their addresses: registered in or bound to one of their
 *   channels (customers who only ordered there are read-only);
 * - sales channels: only their own can be updated, none created or deleted;
 * - the role-to-channel mapping: only unrestricted users change it.
 */
class WriteGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ScopeLookup $lookup,
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

        $allowed = array_flip($ids);
        $commands = $event->getCommands();
        [$newOrders, $newCustomers] = $this->inserted($commands);

        foreach ($commands as $command) {
            $reason = $this->deny($command, $allowed, $newOrders, $newCustomers);
            if ($reason !== null) {
                $event->getExceptions()->add(ChannelScopeException::writeDenied($command->getEntityName(), $reason));
            }
        }
    }

    /**
     * Orders and customers inserted in the same write (their children are checked against them).
     *
     * @param list<WriteCommand> $commands
     *
     * @return array{array<string, ?string>, array<string, array{salesChannelId: string, boundSalesChannelId: ?string}>}
     */
    private function inserted(array $commands): array
    {
        $orders = [];
        $customers = [];
        foreach ($commands as $command) {
            if (!$command instanceof InsertCommand) {
                continue;
            }
            $id = $command->getPrimaryKey()['id'] ?? null;
            if (!\is_string($id)) {
                continue;
            }
            $payload = $command->getPayload();
            if ($command->getEntityName() === 'order') {
                $orders[Uuid::fromBytesToHex($id)] = $this->hex($payload['sales_channel_id'] ?? null);
            } elseif ($command->getEntityName() === 'customer') {
                $customers[Uuid::fromBytesToHex($id)] = [
                    'salesChannelId' => (string) $this->hex($payload['sales_channel_id'] ?? null),
                    'boundSalesChannelId' => $this->hex($payload['bound_sales_channel_id'] ?? null),
                ];
            }
        }

        return [$orders, $customers];
    }

    /**
     * @param array<string, int> $allowed
     * @param array<string, ?string> $newOrders
     * @param array<string, array{salesChannelId: string, boundSalesChannelId: ?string}> $newCustomers
     */
    private function deny(WriteCommand $command, array $allowed, array $newOrders, array $newCustomers): ?string
    {
        $entity = $command->getEntityName();
        $primaryKey = $command->getPrimaryKey();
        $payload = $command->getPayload();
        $insert = $command instanceof InsertCommand;

        if ($entity === AclRoleSalesChannelDefinition::ENTITY_NAME) {
            return 'only unrestricted users change the sales channels of roles';
        }

        if ($entity === 'sales_channel') {
            if ($insert || $command instanceof DeleteCommand) {
                return 'only unrestricted users create or delete sales channels';
            }

            return isset($allowed[$this->hex($primaryKey['id'] ?? null) ?? '']) ? null : 'the sales channel is not one of yours';
        }

        if ($entity === 'order') {
            if (!$insert) {
                $current = $this->lookup->orderChannel((string) ($primaryKey['id'] ?? ''), $primaryKey['version_id'] ?? $this->liveVersion());
                if ($current === null || !isset($allowed[$current])) {
                    return 'the order belongs to another sales channel';
                }
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'orders can only be placed in or moved to your sales channels';
            }

            return null;
        }

        if (\in_array($entity, ScopeRules::ORDER_CHILDREN, true)) {
            if (!$insert) {
                $current = $this->lookup->orderChannelOf($entity, $primaryKey);
                if ($current === null || !isset($allowed[$current])) {
                    return 'its order belongs to another sales channel';
                }
            }
            if (\array_key_exists('order_id', $payload)) {
                $orderId = $this->hex($payload['order_id']) ?? '';
                $channel = \array_key_exists($orderId, $newOrders)
                    ? $newOrders[$orderId]
                    : $this->lookup->orderChannel((string) $payload['order_id'], $payload['order_version_id'] ?? $this->liveVersion());
                if ($channel === null || !isset($allowed[$channel])) {
                    return 'its order belongs to another sales channel';
                }
            }

            return null;
        }

        if ($entity === 'customer') {
            if (!$insert) {
                $current = $this->lookup->customerChannels((string) ($primaryKey['id'] ?? ''));
                if (!$this->ownsCustomer($current, $allowed)) {
                    return 'the customer is registered in another sales channel';
                }
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'customers can only be registered in your sales channels';
            }
            $bound = $payload['bound_sales_channel_id'] ?? null;
            if ($bound !== null && !isset($allowed[$this->hex($bound) ?? ''])) {
                return 'customers can only be bound to your sales channels';
            }

            return null;
        }

        if ($entity === 'customer_address') {
            if (!$insert && !$this->ownsCustomer($this->lookup->customerChannelsOfAddress($primaryKey), $allowed)) {
                return 'its customer is registered in another sales channel';
            }
            if (\array_key_exists('customer_id', $payload)) {
                $customerId = $this->hex($payload['customer_id']) ?? '';
                $customer = $newCustomers[$customerId] ?? $this->lookup->customerChannels((string) $payload['customer_id']);
                if (!$this->ownsCustomer($customer, $allowed)) {
                    return 'its customer is registered in another sales channel';
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param array{salesChannelId: string, boundSalesChannelId: ?string}|null $customer
     * @param array<string, int> $allowed
     */
    private function ownsCustomer(?array $customer, array $allowed): bool
    {
        if ($customer === null) {
            return false;
        }

        return isset($allowed[$customer['salesChannelId']])
            || ($customer['boundSalesChannelId'] !== null && isset($allowed[$customer['boundSalesChannelId']]));
    }

    private function hex(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    private function liveVersion(): string
    {
        return Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
    }
}
