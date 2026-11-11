<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;

/**
 * Refuses writes of a restricted admin user.
 *
 * Register an implementation as a service with the tag
 * Tags::WRITE_RULE ("mn_channel_scope.write_rule"). Each command of a restricted user's write
 * (also Sync API batches, versioned writes, nested writes) is passed to every
 * rule before it is executed; any reason returned refuses the whole write
 * with MN_CHANNEL_SCOPE__WRITE_DENIED.
 *
 * Commands are in storage format: column names, ids as bytes.
 */
interface WriteRule
{
    /**
     * Why the command is refused, or null when it is allowed or not this
     * rule's concern.
     */
    public function deny(WriteCommand $command, WriteScope $scope): ?string;
}
