<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Rule;

/**
 * Reads the stored sales channel column of a record.
 */
interface ChannelColumnLookup
{
    /**
     * The stored value (hex) of the column, '' when it is NULL, null when the
     * record doesn't exist.
     *
     * @param array<string, string> $primaryKey storage format (bytes)
     */
    public function channelOf(string $table, string $column, array $primaryKey): ?string;
}
