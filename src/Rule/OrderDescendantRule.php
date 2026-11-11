<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Records that belong to an order through more than one step: delivery
 * positions, download items, payment captures, refunds, refund positions,
 * document files. They follow their order like the direct order details
 * (ScopeRules::ORDER_CHILDREN): visible and changeable only when the order
 * belongs to one of the user's sales channels.
 *
 * Writes find the order by walking up the parents, through records inserted
 * in the same write first, then through the stored records.
 */
final class OrderDescendantRule implements ReadRule, WriteRule
{
    /** entity => [path to the order, parent column, parent entity] */
    public const DESCENDANTS = [
        'order_delivery_position' => ['orderDelivery.order.', 'order_delivery_id', 'order_delivery'],
        'order_line_item_download' => ['orderLineItem.order.', 'order_line_item_id', 'order_line_item'],
        'order_transaction_capture' => ['transaction.order.', 'order_transaction_id', 'order_transaction'],
        'order_transaction_capture_refund' => ['transactionCapture.transaction.order.', 'capture_id', 'order_transaction_capture'],
        // through the line item: Shopware's association to the refund can't be filtered by (wrong storage name)
        'order_transaction_capture_refund_position' => ['orderLineItem.order.', 'refund_id', 'order_transaction_capture_refund'],
        'document_file' => ['document.order.', 'document_id', 'document'],
    ];

    public function __construct(private readonly ScopeLookup $lookup)
    {
    }

    public function readFilter(string $entity, array $salesChannelIds): ?Filter
    {
        $descendant = self::DESCENDANTS[$entity] ?? null;

        return $descendant === null ? null : new EqualsAnyFilter($descendant[0] . 'salesChannelId', $salesChannelIds);
    }

    public function deny(WriteCommand $command, WriteScope $scope): ?string
    {
        $entity = $command->getEntityName();
        if (!isset(self::DESCENDANTS[$entity])) {
            return null;
        }

        if (!$command instanceof InsertCommand) {
            $id = $command->getPrimaryKey()['id'] ?? null;
            $stored = \is_string($id) ? $this->orderChannel($entity, Uuid::fromBytesToHex($id), $scope) : null;
            if ($stored === null || !$scope->isOwn($stored)) {
                return 'its order belongs to another sales channel';
            }
        }

        [, $column, $parent] = self::DESCENDANTS[$entity];
        $parentId = $command->getPayload()[$column] ?? null;
        if ($command instanceof InsertCommand || $parentId !== null) {
            $channel = \is_string($parentId) && \strlen($parentId) === 16 ? $this->orderChannel($parent, Uuid::fromBytesToHex($parentId), $scope) : null;
            if ($channel === null || !$scope->isOwn($channel)) {
                return 'its order belongs to another sales channel';
            }
        }

        return null;
    }

    /**
     * Sales channel (hex) of the order a record belongs to, null when unknown.
     */
    private function orderChannel(string $entity, string $id, WriteScope $scope): ?string
    {
        for ($depth = 0; $depth < 6; ++$depth) {
            if (\in_array($entity, ScopeRules::ORDER_CHILDREN, true)) {
                $insert = $scope->insertOf($entity, $id);
                if ($insert !== null) {
                    $orderId = $insert->getPayload()['order_id'] ?? null;
                    $orderInsert = \is_string($orderId) ? $scope->insertOf('order', Uuid::fromBytesToHex($orderId)) : null;
                    $channel = $orderInsert?->getPayload()['sales_channel_id'] ?? null;
                    if (\is_string($channel)) {
                        return Uuid::fromBytesToHex($channel);
                    }

                    return \is_string($orderId) ? $this->lookup->orderChannel($orderId, $insert->getPayload()['order_version_id'] ?? '') : null;
                }

                return $this->lookup->orderChannelOf($entity, ['id' => Uuid::fromHexToBytes($id)]);
            }

            $descendant = self::DESCENDANTS[$entity] ?? null;
            if ($descendant === null) {
                return null;
            }
            [, $column, $parent] = $descendant;
            $insert = $scope->insertOf($entity, $id);
            $parentId = $insert !== null
                ? $insert->getPayload()[$column] ?? null
                : $this->lookup->parentOf($entity, $column, Uuid::fromHexToBytes($id));
            if (!\is_string($parentId) || \strlen($parentId) !== 16) {
                return null;
            }
            $entity = $parent;
            $id = Uuid::fromBytesToHex($parentId);
        }

        return null;
    }
}
