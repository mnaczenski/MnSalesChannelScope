<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Scope\RoleChannelLoader;

final class StaticRoleChannelLoader implements RoleChannelLoader
{
    public int $calls = 0;

    /**
     * @param array<string, array<string, list<string>>> $users user id => role id => channel ids
     */
    public function __construct(private readonly array $users)
    {
    }

    public function load(string $userId): array
    {
        ++$this->calls;

        return $this->users[$userId] ?? [];
    }
}
