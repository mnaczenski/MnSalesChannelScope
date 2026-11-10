<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;

final class Ids
{
    public const DE = '0190aaaaaaaaaaaaaaaaaaaaaaaaaa01';
    public const EN = '0190aaaaaaaaaaaaaaaaaaaaaaaaaa02';
    public const B2B = '0190aaaaaaaaaaaaaaaaaaaaaaaaaa03';

    public const DE_USER = '0190bbbbbbbbbbbbbbbbbbbbbbbbbb01';
    public const FREE_USER = '0190bbbbbbbbbbbbbbbbbbbbbbbbbb02';

    /** A scope where DE_USER is limited to the DE channel and FREE_USER is unrestricted. */
    public static function scope(): ChannelScope
    {
        return new ChannelScope(new StaticRoleChannelLoader([
            self::DE_USER => ['role-de' => [self::DE]],
            self::FREE_USER => ['role-free' => []],
        ]));
    }

    public static function context(string $userId = self::DE_USER): Context
    {
        return new Context(new AdminApiSource($userId));
    }
}
