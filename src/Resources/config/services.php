<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Command\ApplyPresetCommand;
use Mn\SalesChannelScope\Controller\ChannelScopeController;
use Mn\SalesChannelScope\Dashboard\ScopedOrderAmountService;
use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Mn\SalesChannelScope\Extension\AclRoleExtension;
use Mn\SalesChannelScope\Preset\PermissionPresets;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Scope\DbalRoleChannelLoader;
use Mn\SalesChannelScope\Scope\RoleChannelLoader;
use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Subscriber\ReadFilterSubscriber;
use Mn\SalesChannelScope\Subscriber\WriteGuardSubscriber;
use Mn\SalesChannelScope\Write\DbalScopeLookup;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Administration\Dashboard\OrderAmountService;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
    $services->set(ScopeRules::class);

    $services->set(ReadFilterSubscriber::class)
        ->args([service(ChannelScope::class), service(ScopeRules::class)])
        ->tag('kernel.event_subscriber');

    $services->set(DbalScopeLookup::class)->args([service(Connection::class)]);
    $services->alias(ScopeLookup::class, DbalScopeLookup::class);
    $services->set(WriteGuardSubscriber::class)
        ->args([service(ChannelScope::class), service(ScopeLookup::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ChannelScopeController::class)
        ->public()
        ->args([service(ChannelScope::class)])
        ->call('setContainer', [service('service_container')])
        ->tag('controller.service_arguments');

    $services->set(PermissionPresets::class)->args([__DIR__ . '/presets']);
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
