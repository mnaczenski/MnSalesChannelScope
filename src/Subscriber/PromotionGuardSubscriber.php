<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Promotion\PromotionSource;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Promotions of a limited user. A promotion belongs to the sales channels it
 * is assigned to (promotion_sales_channel):
 *
 * - it and everything that belongs to it (discounts and their prices and
 *   rules, set groups and their rules, individual codes, cart, order and
 *   persona rules, persona customers, translations) only change when it is
 *   assigned to their channels only; shared promotions are read-only;
 * - a new promotion needs, in the same write, an assignment to one of their
 *   channels, and only to theirs;
 * - assignments only to their channels;
 * - replacing or adding individual codes (/api/_action/promotion/codes/...,
 *   which deletes the old codes with plain SQL) only for such promotions.
 */
class PromotionGuardSubscriber implements EventSubscriberInterface
{
    /** Tables with a promotion_id column (in the primary key or as a field). */
    private const PROMOTION_CHILDREN = [
        'promotion_discount',
        'promotion_setgroup',
        'promotion_individual_code',
        'promotion_translation',
        'promotion_cart_rule',
        'promotion_order_rule',
        'promotion_persona_rule',
        'promotion_persona_customer',
    ];

    /** Tables that belong to a discount or set group: entity => [column, parent entity]. */
    private const GRANDCHILDREN = [
        'promotion_discount_prices' => ['discount_id', 'promotion_discount'],
        'promotion_discount_rule' => ['discount_id', 'promotion_discount'],
        'promotion_setgroup_rule' => ['setgroup_id', 'promotion_setgroup'],
    ];

    public const CODE_ROUTES = ['api.action.promotion.codes.replace-individual', 'api.action.promotion.codes.add-individual'];

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly PromotionSource $source,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'onPreWrite',
            KernelEvents::CONTROLLER => ['onController', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
        ];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null) {
            return;
        }

        $commands = $event->getCommands();
        [$promotions, $parents] = $this->inserted($commands);

        foreach ($commands as $command) {
            $reason = $this->deny($command, $ids, $promotions, $parents);
            if ($reason !== null) {
                $event->getExceptions()->add(ChannelScopeException::writeDenied($command->getEntityName(), $reason));
            }
        }
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!\in_array($request->attributes->get('_route'), self::CODE_ROUTES, true)) {
            return;
        }
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        $ids = $context instanceof Context ? $this->scope->salesChannelIds($context) : null;
        if ($ids === null) {
            return;
        }

        if (!$this->isEditable(strtolower((string) $request->request->get('promotionId')), $ids)) {
            throw ChannelScopeException::writeDenied('promotion_individual_code', 'the promotion is also used by other sales channels');
        }
    }

    /**
     * @param list<string> $ids
     * @param array<string, list<string>> $promotions new promotion id => channels assigned in this write
     * @param array<string, array<string, ?string>> $parents entity => new id => promotion id
     */
    private function deny(WriteCommand $command, array $ids, array $promotions, array $parents): ?string
    {
        $entity = $command->getEntityName();
        $payload = $command->getPayload();
        $primaryKey = $command->getPrimaryKey();
        $insert = $command instanceof InsertCommand;
        $id = $this->hex($primaryKey['id'] ?? null);

        if ($entity === 'promotion') {
            if (!$insert) {
                return $this->denyPromotion($id, $ids, $promotions);
            }
            $channels = $promotions[$id] ?? [];

            return $channels === [] ? 'a new promotion needs one of your sales channels' : null;
        }

        if ($entity === 'promotion_sales_channel') {
            $channel = $this->hex($payload['sales_channel_id'] ?? null) ?? ($insert ? null : $this->source->assignmentChannel((string) $id));
            if ($channel === null || !\in_array($channel, $ids, true)) {
                return 'promotions can only be assigned to your sales channels';
            }
            $promotion = $this->hex($payload['promotion_id'] ?? null) ?? ($insert ? null : $this->source->promotionOf($entity, (string) $id));

            return $this->denyPromotion($promotion, $ids, $promotions);
        }

        if (\in_array($entity, self::PROMOTION_CHILDREN, true)) {
            if (!$insert && $id !== null && !\array_key_exists('promotion_id', $primaryKey)) {
                $reason = $this->denyPromotion($this->source->promotionOf($entity, $id), $ids, $promotions);
                if ($reason !== null) {
                    return $reason;
                }
            }
            $promotion = $this->hex($primaryKey['promotion_id'] ?? $payload['promotion_id'] ?? null);

            return $promotion === null && !$insert ? null : $this->denyPromotion($promotion, $ids, $promotions);
        }

        if (isset(self::GRANDCHILDREN[$entity])) {
            [$column, $parent] = self::GRANDCHILDREN[$entity];
            if (!$insert && $id !== null && !\array_key_exists($column, $primaryKey)) {
                $reason = $this->denyPromotion($this->source->promotionOf($entity, $id), $ids, $promotions);
                if ($reason !== null) {
                    return $reason;
                }
            }
            $parentId = $this->hex($primaryKey[$column] ?? $payload[$column] ?? null);
            if ($parentId === null) {
                return $insert ? 'its promotion is unknown' : null;
            }
            $promotion = \array_key_exists($parentId, $parents[$parent] ?? []) ? $parents[$parent][$parentId] : $this->source->promotionOf($parent, $parentId);

            return $this->denyPromotion($promotion, $ids, $promotions);
        }

        return null;
    }

    /**
     * @param list<string> $ids
     * @param array<string, list<string>> $promotions
     */
    private function denyPromotion(?string $promotion, array $ids, array $promotions): ?string
    {
        if ($promotion !== null && \array_key_exists($promotion, $promotions)) {
            return null;
        }

        return $promotion !== null && $this->isEditable($promotion, $ids) ? null : 'the promotion is also used by other sales channels';
    }

    /**
     * @param list<string> $ids
     */
    private function isEditable(string $promotion, array $ids): bool
    {
        $channels = $this->source->channels($promotion);

        return $channels !== null && $channels !== [] && array_diff($channels, $ids) === [];
    }

    /**
     * New promotions with the channels assigned to them in this write, and
     * new discounts and set groups with their promotion.
     *
     * @param list<WriteCommand> $commands
     *
     * @return array{array<string, list<string>>, array<string, array<string, ?string>>}
     */
    private function inserted(array $commands): array
    {
        $promotions = [];
        $parents = [];
        foreach ($commands as $command) {
            $id = $this->hex($command->getPrimaryKey()['id'] ?? null);
            if (!$command instanceof InsertCommand || $id === null) {
                continue;
            }
            if ($command->getEntityName() === 'promotion') {
                $promotions[$id] ??= [];
            } elseif (\in_array($command->getEntityName(), ['promotion_discount', 'promotion_setgroup'], true)) {
                $parents[$command->getEntityName()][$id] = $this->hex($command->getPayload()['promotion_id'] ?? null);
            }
        }
        foreach ($commands as $command) {
            if ($command instanceof InsertCommand && $command->getEntityName() === 'promotion_sales_channel') {
                $promotion = $this->hex($command->getPayload()['promotion_id'] ?? null);
                if ($promotion !== null && isset($promotions[$promotion])) {
                    $promotions[$promotion][] = (string) $this->hex($command->getPayload()['sales_channel_id'] ?? null);
                }
            }
        }

        return [$promotions, $parents];
    }

    private function hex(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }
}
