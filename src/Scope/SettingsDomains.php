<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

/**
 * Configuration domains (system_config key prefixes like "core.cart") for
 * restricted users. Register one as a service with the tag
 * Tags::SETTINGS_DOMAINS ("mn_channel_scope.settings_domains") to open a plugin's per-channel settings:
 *
 *     $services->set('acme.scope.settings', SettingsDomains::class)
 *         ->args([['AcmeShipping.config'], ['AcmeShipping.config']])
 *         ->tag(Tags::SETTINGS_DOMAINS);
 *
 * @see ConfigRules
 */
final class SettingsDomains
{
    /**
     * @param list<string> $editable changeable for the user's own channels (also readable)
     * @param list<string> $readable readable (own channels and all channels)
     * @param list<string> $hidden never readable, also inside readable domains
     */
    public function __construct(
        public readonly array $editable = [],
        public readonly array $readable = [],
        public readonly array $hidden = [],
    ) {
    }

    /**
     * Shopware's own settings: core.* is readable except credentials and
     * installation-wide domains; the pages of the "Sales channel settings"
     * preset are editable; the storefront's theme bookkeeping is readable.
     */
    public static function shopware(): self
    {
        return new self(
            editable: [
                'core.basicInformation',
                'core.cart',
                'core.loginRegistration',
                'core.newsletter',
                'core.sitemap',
            ],
            readable: ['core', 'storefront'],
            hidden: [
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
            ],
        );
    }
}
