<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

interface RoleChannelLoader
{
    /**
     * The active roles of an admin user with their sales channels.
     *
     * @return array<string, list<string>> role id => sales channel ids (hex); an empty list = unrestricted role
     */
    public function load(string $userId): array;
}
