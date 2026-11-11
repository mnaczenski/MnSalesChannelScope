<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

/**
 * Which configuration values (system_config) a restricted user reads and
 * changes, from all SettingsDomains (Shopware's own, see
 * SettingsDomains::shopware(), and those of other plugins):
 *
 * - read: readable and editable domains, of their sales channels and for all
 *   channels (the administration shows those as inherited values), except
 *   hidden domains;
 * - change: editable domains, only for their own channels.
 *
 * A domain matches the key itself and every key below it ("core.cart"
 * matches "core.cart.maxQuantity").
 */
final class ConfigRules
{
    /** @var list<string> */
    private array $editable = [];

    /** @var list<string> */
    private array $readable = [];

    /** @var list<string> */
    private array $hidden = [];

    /**
     * @param iterable<SettingsDomains> $domains
     */
    public function __construct(iterable $domains = [])
    {
        foreach ([SettingsDomains::shopware(), ...$domains] as $set) {
            array_push($this->editable, ...$set->editable);
            array_push($this->readable, ...$set->readable, ...$set->editable);
            array_push($this->hidden, ...$set->hidden);
        }
        $this->editable = array_values(array_unique($this->editable));
        $this->readable = array_values(array_unique($this->readable));
        $this->hidden = array_values(array_unique($this->hidden));
    }

    public function isReadable(string $keyOrDomain): bool
    {
        return self::matches($keyOrDomain, $this->readable) && !self::matches($keyOrDomain, $this->hidden);
    }

    public function isEditable(string $key): bool
    {
        return self::matches($key, $this->editable) && !self::matches($key, $this->hidden);
    }

    /**
     * @return list<string>
     */
    public function readableDomains(): array
    {
        return $this->readable;
    }

    /**
     * @return list<string>
     */
    public function hiddenDomains(): array
    {
        return $this->hidden;
    }

    /**
     * @param list<string> $domains
     */
    private static function matches(string $keyOrDomain, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($keyOrDomain === $domain || str_starts_with($keyOrDomain, $domain . '.')) {
                return true;
            }
        }

        return false;
    }
}
