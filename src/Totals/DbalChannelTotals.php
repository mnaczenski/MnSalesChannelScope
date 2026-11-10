<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Totals;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Queries mirror Shopware's CustomerMetaFieldSubscriber,
 * ProductReviewCountService, RatingAverageUpdater and OrderStockSubscriber,
 * with the sales channel condition added.
 *
 * @internal
 */
class DbalChannelTotals implements ChannelTotals
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function customers(array $customerIds, array $salesChannelIds): array
    {
        if ($customerIds === [] || $salesChannelIds === []) {
            return [];
        }

        $params = [
            'customerIds' => Uuid::fromHexToBytesList($customerIds),
            'channels' => Uuid::fromHexToBytesList($salesChannelIds),
            'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'state' => OrderStates::STATE_COMPLETED,
        ];
        $types = ['customerIds' => ArrayParameterType::BINARY, 'channels' => ArrayParameterType::BINARY];

        $result = [];
        foreach ($customerIds as $id) {
            $result[strtolower($id)] = ['orderCount' => 0, 'orderTotalAmount' => 0.0, 'lastOrderDate' => null, 'reviewCount' => 0];
        }

        $orders = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(order_customer.customer_id)) AS customer_id,
                    COUNT(`order`.id) AS order_count,
                    ROUND(SUM(`order`.amount_total / `order`.currency_factor), 2) AS order_total_amount,
                    MAX(`order`.order_date_time) AS last_order_date
             FROM order_customer
             INNER JOIN `order` ON `order`.id = order_customer.order_id
                 AND `order`.version_id = order_customer.order_version_id
                 AND `order`.version_id = :version
                 AND `order`.sales_channel_id IN (:channels)
             INNER JOIN state_machine_state ON state_machine_state.id = `order`.state_id
                 AND state_machine_state.technical_name = :state
             WHERE order_customer.customer_id IN (:customerIds)
             GROUP BY order_customer.customer_id',
            $params,
            $types,
        );
        foreach ($orders as $row) {
            $result[$row['customer_id']]['orderCount'] = (int) $row['order_count'];
            $result[$row['customer_id']]['orderTotalAmount'] = (float) $row['order_total_amount'];
            $result[$row['customer_id']]['lastOrderDate'] = $row['last_order_date'] ? new \DateTimeImmutable((string) $row['last_order_date']) : null;
        }

        $reviews = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(customer_id)) AS customer_id, COUNT(*) AS review_count
             FROM product_review
             WHERE customer_id IN (:customerIds) AND status = 1 AND sales_channel_id IN (:channels)
             GROUP BY customer_id',
            $params,
            $types,
        );
        foreach ($reviews as $row) {
            $result[$row['customer_id']]['reviewCount'] = (int) $row['review_count'];
        }

        return $result;
    }

    public function ratings(array $rootIds, array $salesChannelIds): array
    {
        if ($rootIds === [] || $salesChannelIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(IFNULL(product.parent_id, product.id))) AS id, AVG(NULLIF(product_review.points, 0)) AS average
             FROM product_review
             INNER JOIN product ON product.id = product_review.product_id AND product.version_id = :version
             WHERE product_review.status = 1
               AND product_review.sales_channel_id IN (:channels)
               AND (product.id IN (:ids) OR product.parent_id IN (:ids))
             GROUP BY IFNULL(product.parent_id, product.id)',
            [
                'ids' => Uuid::fromHexToBytesList($rootIds),
                'channels' => Uuid::fromHexToBytesList($salesChannelIds),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['ids' => ArrayParameterType::BINARY, 'channels' => ArrayParameterType::BINARY],
        );

        $result = [];
        foreach ($rows as $row) {
            if ($row['average'] !== null) {
                $result[$row['id']] = (float) $row['average'];
            }
        }

        return $result;
    }

    public function sales(array $productIds, array $salesChannelIds): array
    {
        if ($productIds === [] || $salesChannelIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(order_line_item.product_id)) AS id, SUM(order_line_item.quantity) AS sales
             FROM order_line_item
             INNER JOIN `order` ON `order`.id = order_line_item.order_id
                 AND `order`.version_id = order_line_item.order_version_id
                 AND `order`.version_id = :version
                 AND `order`.sales_channel_id IN (:channels)
             INNER JOIN state_machine_state ON state_machine_state.id = `order`.state_id
                 AND state_machine_state.technical_name != :cancelled
             WHERE order_line_item.product_id IN (:ids) AND order_line_item.type = :type
             GROUP BY order_line_item.product_id',
            [
                'ids' => Uuid::fromHexToBytesList($productIds),
                'channels' => Uuid::fromHexToBytesList($salesChannelIds),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'cancelled' => OrderStates::STATE_CANCELLED,
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            ],
            ['ids' => ArrayParameterType::BINARY, 'channels' => ArrayParameterType::BINARY],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = (int) $row['sales'];
        }

        return $result;
    }
}
