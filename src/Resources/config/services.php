<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Cms\CmsUsageSource;
use Mn\SalesChannelScope\Cms\DbalCmsUsageSource;
use Mn\SalesChannelScope\Category\CategoryTreeSource;
use Mn\SalesChannelScope\Category\DbalCategoryTreeSource;
use Mn\SalesChannelScope\Command\ApplyPresetCommand;
use Mn\SalesChannelScope\Controller\ChannelScopeController;
use Mn\SalesChannelScope\Dashboard\ScopedOrderAmountService;
use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Mn\SalesChannelScope\Extension\AclRoleExtension;
use Mn\SalesChannelScope\Preset\PermissionPresets;
use Mn\SalesChannelScope\Preset\PresetDirectory;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Scope\DbalRoleChannelLoader;
use Mn\SalesChannelScope\Scope\RoleChannelLoader;
use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Scope\ConfigRules;
use Mn\SalesChannelScope\Scope\SettingsDomains;
use Mn\SalesChannelScope\Rule\ChannelColumnLookup;
use Mn\SalesChannelScope\Rule\DbalChannelColumnLookup;
use Mn\SalesChannelScope\Rule\SalesChannelColumnRule;
use Mn\SalesChannelScope\Tags;
use Mn\SalesChannelScope\Subscriber\WriteRuleSubscriber;
use Mn\SalesChannelScope\Subscriber\AdminSearchGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\ChannelTotalsSubscriber;
use Mn\SalesChannelScope\Subscriber\PromotionGuardSubscriber;
use Mn\SalesChannelScope\Promotion\DbalPromotionSource;
use Mn\SalesChannelScope\Promotion\PromotionSource;
use Mn\SalesChannelScope\Subscriber\CloneGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\CmsGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\ImitateCustomerGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\SystemConfigGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\ThemeGuardSubscriber;
use Mn\SalesChannelScope\Subscriber\ReadFilterSubscriber;
use Mn\SalesChannelScope\Subscriber\WriteGuardSubscriber;
use Mn\SalesChannelScope\Totals\ChannelTotals;
use Mn\SalesChannelScope\Totals\DbalChannelTotals;
use Mn\SalesChannelScope\Write\DbalScopeLookup;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Administration\Dashboard\OrderAmountService;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(AclRoleSalesChannelDefinition::class)
        ->tag('shopware.entity.definition', ['entity' => AclRoleSalesChannelDefinition::ENTITY_NAME]);
    $services->set(AclRoleExtension::class)->tag('shopware.entity.extension');

    $services->set(DbalRoleChannelLoader::class)->args([service(Connection::class)]);
    $services->alias(RoleChannelLoader::class, DbalRoleChannelLoader::class);
    $services->set(ChannelScope::class)
        ->args([service(RoleChannelLoader::class)])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->set(DbalCategoryTreeSource::class)
        ->args([service(Connection::class)])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(CategoryTreeSource::class, DbalCategoryTreeSource::class);
    $services->set(CategoryTrees::class)->args([service(CategoryTreeSource::class)]);
    $services->set(DbalCmsUsageSource::class)
        ->args([service(Connection::class)])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(CmsUsageSource::class, DbalCmsUsageSource::class);
    $services->set(CmsPages::class)
        ->args([service(CmsUsageSource::class), service(CategoryTrees::class)])
        ->tag('kernel.reset', ['method' => 'reset']);
    // Extension point: further settings domains (see docs/extending.md).
    $services->set(ConfigRules::class)->args([tagged_iterator(Tags::SETTINGS_DOMAINS)]);

    $services->set(ScopeRules::class)
        ->args([service(CategoryTrees::class), service(CmsPages::class), service(ConfigRules::class)])
        ->tag(Tags::READ_RULE, ['priority' => 100]);

    // Extension point: read and write rules (see docs/extending.md).
    $services->set(DbalChannelColumnLookup::class)->args([service(Connection::class)]);
    $services->alias(ChannelColumnLookup::class, DbalChannelColumnLookup::class);
    $services->set(WriteRuleSubscriber::class)
        ->args([service(ChannelScope::class), tagged_iterator(Tags::WRITE_RULE)])
        ->tag('kernel.event_subscriber');

    // Newsletter recipients belong to the sales channel they subscribed in.
    $services->set('mn_channel_scope.rule.newsletter_recipient', SalesChannelColumnRule::class)
        ->args(['newsletter_recipient', service(ChannelColumnLookup::class)])
        ->tag(Tags::READ_RULE)
        ->tag(Tags::WRITE_RULE);

    $services->set(CmsGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(CmsPages::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ReadFilterSubscriber::class)
        ->args([service(ChannelScope::class), tagged_iterator(Tags::READ_RULE)])
        ->tag('kernel.event_subscriber');

    $services->set(DbalChannelTotals::class)->args([service(Connection::class)]);
    $services->alias(ChannelTotals::class, DbalChannelTotals::class);
    $services->set(ChannelTotalsSubscriber::class)
        ->args([service(ChannelScope::class), service(ChannelTotals::class)])
        ->tag('kernel.event_subscriber');

    $services->set(DbalScopeLookup::class)->args([service(Connection::class)]);
    $services->alias(ScopeLookup::class, DbalScopeLookup::class);
    $services->set(WriteGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(ScopeLookup::class), service(CategoryTrees::class), service(ConfigRules::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ImitateCustomerGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(ScopeLookup::class)])
        ->tag('kernel.event_subscriber');

    $services->set(CloneGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(DefinitionInstanceRegistry::class), service(ScopeLookup::class), service(CmsPages::class)])
        ->tag('kernel.event_subscriber');

    $services->set(DbalPromotionSource::class)->args([service(Connection::class)]);
    $services->alias(PromotionSource::class, DbalPromotionSource::class);
    $services->set(PromotionGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(PromotionSource::class)])
        ->tag('kernel.event_subscriber');

    $services->set(AdminSearchGuardSubscriber::class)
        ->args([service(ChannelScope::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ThemeGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(ScopeLookup::class)])
        ->tag('kernel.event_subscriber');

    $services->set(SystemConfigGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(ConfigRules::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ChannelScopeController::class)
        ->public()
        ->args([service(ChannelScope::class), service(CategoryTrees::class), service(CmsPages::class), service(PermissionPresets::class)])
        ->call('setContainer', [service('service_container')])
        ->tag('controller.service_arguments');

    // Extension point: further preset directories (see docs/extending.md).
    $services->set('mn_channel_scope.presets.core', PresetDirectory::class)
        ->args([__DIR__ . '/presets'])
        ->tag(Tags::PRESET_DIRECTORY, ['priority' => 100]);
    $services->set(PermissionPresets::class)->args([tagged_iterator(Tags::PRESET_DIRECTORY)]);
    $services->set(ApplyPresetCommand::class)
        ->args([service(PermissionPresets::class), service(Connection::class)])
        ->tag('console.command');

    $services->set(ScopedOrderAmountService::class)
        ->decorate(OrderAmountService::class)
        ->args([
            service('.inner'),
            service(ChannelScope::class),
            service(Connection::class),
            service(CashRounding::class),
            param('shopware.dbal.time_zone_support_enabled'),
        ]);
};
