<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class ChannelScopeException extends HttpException
{
    public const WRITE_DENIED = 'MN_CHANNEL_SCOPE__WRITE_DENIED';

    public static function writeDenied(string $entity, string $reason): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::WRITE_DENIED,
            'Your role may not change this {{ entity }}: {{ reason }}',
            ['entity' => $entity, 'reason' => $reason],
        );
    }
}
