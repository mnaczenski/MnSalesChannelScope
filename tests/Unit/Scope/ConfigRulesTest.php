<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Scope;

use Mn\SalesChannelScope\Scope\ConfigRules;
use Mn\SalesChannelScope\Scope\SettingsDomains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigRules::class)]
#[CoversClass(SettingsDomains::class)]
class ConfigRulesTest extends TestCase
{
    public function testShopwaresSettingsWithoutCredentials(): void
    {
        $rules = new ConfigRules();

        static::assertTrue($rules->isReadable('core.cart'));
        static::assertTrue($rules->isReadable('core.cart.maxQuantity'));
        static::assertTrue($rules->isReadable('storefront.themeSeed'));
        static::assertFalse($rules->isReadable('core.mailerSettings.password'));
        static::assertFalse($rules->isReadable('core.store.licenseKey'));
        static::assertFalse($rules->isReadable('SwagPayPal.settings.clientSecret'));
        static::assertFalse($rules->isReadable('corex.something'), 'a domain matches whole segments only');

        static::assertTrue($rules->isEditable('core.cart.maxQuantity'));
        static::assertFalse($rules->isEditable('core.listing.productsPerPage'));
        static::assertFalse($rules->isEditable('storefront.themeSeed'));
    }

    public function testPluginsOpenTheirOwnSettings(): void
    {
        $rules = new ConfigRules([new SettingsDomains(editable: ['AcmeShipping.config'], hidden: ['AcmeShipping.config.apiSecret'])]);

        static::assertTrue($rules->isEditable('AcmeShipping.config.carrier'));
        static::assertTrue($rules->isReadable('AcmeShipping.config.carrier'), 'editable domains are readable');
        static::assertFalse($rules->isReadable('AcmeShipping.config.apiSecret'));
        static::assertFalse($rules->isEditable('AcmeShipping.config.apiSecret'));
        static::assertContains('AcmeShipping.config', $rules->readableDomains());
        static::assertContains('core.store', $rules->hiddenDomains());
    }
}
