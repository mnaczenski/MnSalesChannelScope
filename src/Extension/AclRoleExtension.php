<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Extension;

use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * acl_role.mnSalesChannels: the sales channels a role is limited to.
 */
class AclRoleExtension extends EntityExtension
{
    public const FIELD = 'mnSalesChannels';

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new ManyToManyAssociationField(self::FIELD, SalesChannelDefinition::class, AclRoleSalesChannelDefinition::class, 'acl_role_id', 'sales_channel_id'))
                ->addFlags(new ApiAware(AdminApiSource::class))
        );
    }

    public function getEntityName(): string
    {
        return AclRoleDefinition::ENTITY_NAME;
    }
}
