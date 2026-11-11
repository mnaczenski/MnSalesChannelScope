<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

/**
 * Which configuration values (system_config) a restricted user reads and
 * changes.
 *
 * - Read: Shopware's own settings (core.*) of their sales channels and those
 *   that apply to all channels, which the administration shows as inherited
 *   values. Not: plugin and app settings, and core settings that hold
 *   credentials or affect the whole installation (mailer, store, license, ...).
 *   Also the storefront's theme bookkeeping (pending theme, theme seeds).
 * - Change: the sales channel specific settings pages of the "Sales channel
 *   settings" preset, and only for their own channels.
 */
final class ConfigRules
{
    /** Domains of the settings pages a restricted user may change, per own channel. */
    public const EDITABLE_DOMAINS = [
        'core.basicInformation',
        'core.cart',
        'core.loginRegistration',
        'core.newsletter',
        'core.sitemap',
    ];

    /** Core domains that hold credentials or concern the whole installation. */
    public const HIDDEN_DOMAINS = [
        'core.app',
        'core.frw',
        'core.logging',
        'core.mailerSettings',
        'core.mediaAiTag',
        'core.services',
        'core.store',
        'core.update',
        'core.usageData',
        'core.userPermission',
        'core.webhook',
    ];

    /** Other domains the administration reads on pages of the presets (theme bookkeeping on the sales channel page). */
    public const READABLE_DOMAINS = ['storefront'];

    public static function isReadable(string $keyOrDomain): bool
    {
        if (self::inDomains($keyOrDomain, self::READABLE_DOMAINS)) {
            return true;
        }
        if (!str_starts_with($keyOrDomain, 'core.')) {
            return false;
        }

        return !self::inDomains($keyOrDomain, self::HIDDEN_DOMAINS);
    }

    public static function isEditable(string $key): bool
    {
        return self::inDomains($key, self::EDITABLE_DOMAINS);
    }

    /**
     * @param list<string> $domains
     */
    private static function inDomains(string $keyOrDomain, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($keyOrDomain === $domain || str_starts_with($keyOrDomain, $domain . '.')) {
                return true;
            }
        }

        return false;
    }
}
