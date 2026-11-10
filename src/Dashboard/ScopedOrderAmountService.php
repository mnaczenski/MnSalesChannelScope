<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Dashboard;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Administration\Dashboard\OrderAmountService;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The dashboard turnover chart for restricted users: Shopware's query
 * (OrderAmountService, raw SQL) limited to their sales channels.
 * Unrestricted users get Shopware's own result.
 *
 * OrderAmountService is internal and has no interface, so this POC extends
 * it; a core version would add the condition there.
 */
readonly class ScopedOrderAmountService extends OrderAmountService
{
    public function __construct(
        private OrderAmountService $inner,
        private ChannelScope $scope,
        private Connection $scopedConnection,
        private CashRounding $scopedRounding,
        private bool $scopedTimeZoneSupport,
    ) {
        parent::__construct($scopedConnection, $scopedRounding, $scopedTimeZoneSupport);
    }

    public function load(Context $context, string $since, bool $paid, string $timezone = 'UTC'): array
    {
        $ids = $this->scope->salesChannelIds($context);
        if ($ids === null) {
            return $this->inner->load($context, $since, $paid, $timezone);
        }

        $query = $this->scopedConnection->createQueryBuilder();
        $accessor = '`order`.order_date_time';
        if ($this->scopedTimeZoneSupport || Feature::isActive('v6.8.0.0')) {
            $accessor = 'IFNULL(CONVERT_TZ(' . $accessor . ', :dbtimezone, :timezone), ' . $accessor . ')';
            $query->setParameter('dbtimezone', '+00:00');
            $query->setParameter('timezone', $timezone);
        }

        $query->select(
            'DATE_FORMAT(' . $accessor . ', \'%Y-%m-%d\') as `date`',
            'COUNT(`order`.id) as `count`',
            'SUM(`order`.amount_total / IFNULL(`order`.currency_factor, currency.factor)) as `amount`',
        );
        $query->from('`order`');
        $query->leftJoin('`order`', 'currency', 'currency', '`order`.currency_factor is null AND currency.id = `order`.currency_id');
        $query->andWhere('`order`.order_date_time >= :since');
        $query->andWhere('`order`.version_id = :version');
        $query->andWhere('`order`.sales_channel_id IN (:salesChannelIds)');
        $query->groupBy('`date`');

        if ($paid) {
            $paidId = $this->scopedConnection->fetchOne(
                'SELECT state.id FROM state_machine_state state
                 INNER JOIN state_machine ON state.state_machine_id = state_machine.id
                 WHERE state_machine.technical_name = :state_machine AND state.technical_name = :state',
                ['state_machine' => OrderTransactionStates::STATE_MACHINE, 'state' => OrderTransactionStates::STATE_PAID],
            );
            $query->innerJoin('`order`', 'order_transaction', 'transactions', 'transactions.order_id = `order`.id AND transactions.version_id = `order`.version_id AND transactions.state_id = :paidId');
            $query->setParameter('paidId', $paidId);
        }

        $query->setParameter('since', $since);
        $query->setParameter('version', Uuid::fromHexToBytes(Defaults::LIVE_VERSION));
        $query->setParameter('salesChannelIds', Uuid::fromHexToBytesList($ids), ArrayParameterType::BINARY);
        $query->orderBy('`date`', 'ASC');

        $mapped = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $mapped[] = [
                'date' => $row['date'],
                'count' => (int) $row['count'],
                'amount' => $this->scopedRounding->cashRound((float) $row['amount'], $context->getRounding()),
            ];
        }

        return $mapped;
    }
}
