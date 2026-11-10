<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which sales channels the current admin user is limited to.
 *
 * A user's channels are the union over their roles. Unrestricted (null):
 * admins, integrations, anything outside the Admin API (storefront, Store API,
 * CLI), system-scope work, users without roles, and users with at least one
 * role that has no sales channels.
 */
class ChannelScope implements ResetInterface
{
    /** @var array<string, list<string>|null> */
    private array $cache = [];

    public function __construct(private readonly RoleChannelLoader $loader)
    {
    }

    /**
     * @return list<string>|null sales channel ids (hex), null = unrestricted
     */
    public function salesChannelIds(Context $context): ?array
    {
        if ($context->getScope() === Context::SYSTEM_SCOPE) {
            return null;
        }

        $source = $context->getSource();
        if (!$source instanceof AdminApiSource || $source->isAdmin()) {
            return null;
        }

        $userId = $source->getUserId();
        if ($userId === null) {
            return null;
        }

        if (!\array_key_exists($userId, $this->cache)) {
            $this->cache[$userId] = $this->resolve($userId);
        }

        return $this->cache[$userId];
    }

    public function isRestricted(Context $context): bool
    {
        return $this->salesChannelIds($context) !== null;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<string>|null
     */
    private function resolve(string $userId): ?array
    {
        $roles = $this->loader->load($userId);
        if ($roles === []) {
            return null;
        }

        $ids = [];
        foreach ($roles as $channels) {
            if ($channels === []) {
                return null;
            }
            foreach ($channels as $channel) {
                $ids[strtolower($channel)] = true;
            }
        }

        return array_keys($ids);
    }
}
