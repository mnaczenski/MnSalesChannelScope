<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Controller;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Preset\PermissionPresets;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tells the administration whether the current user is limited and to which
 * sales channels, so screens can mark shared records as read-only. The data
 * itself is protected by the read filter and write guard, not by this.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class ChannelScopeController extends AbstractController
{
    public function __construct(
        private readonly ChannelScope $scope,
        private readonly CategoryTrees $categories,
        private readonly CmsPages $cms,
        private readonly PermissionPresets $presets,
    ) {
    }

    /**
     * The permission presets for the role page (without the expanded
     * privileges, which the administration computes itself when saving).
     */
    #[Route(
        path: '/api/_action/mn-channel-scope/presets',
        name: 'api.action.mn-channel-scope.presets',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['acl_role:read']],
        methods: ['GET'],
    )]
    public function presets(): JsonResponse
    {
        return new JsonResponse(array_map(static fn (array $preset) => [
            'name' => $preset['name'],
            'label' => $preset['label'],
            'description' => $preset['description'],
            'roles' => $preset['roles'],
            'additionalPrivileges' => $preset['additionalPrivileges'],
        ], $this->presets->all()));
    }

    /**
     * Whether the current user may edit a Shopping Experience layout, and the
     * sales channels it belongs to (only for the read-only hint).
     */
    #[Route(path: '/api/_action/mn-channel-scope/cms-page/{pageId}', name: 'api.action.mn-channel-scope.cms-page', methods: ['GET'])]
    public function cmsPage(string $pageId, Context $context): JsonResponse
    {
        $ids = $this->scope->salesChannelIds($context);
        if ($ids === null) {
            return new JsonResponse(['restricted' => false, 'editable' => true, 'salesChannelIds' => []]);
        }

        return new JsonResponse([
            'restricted' => true,
            'editable' => $this->cms->isEditable($pageId, $ids),
            'salesChannelIds' => $this->cms->isVisible($pageId, $ids) ? ($this->cms->channels($pageId) ?? []) : [],
        ]);
    }

    #[Route(path: '/api/_action/mn-channel-scope/me', name: 'api.action.mn-channel-scope.me', methods: ['GET'])]
    public function me(Context $context): JsonResponse
    {
        $ids = $this->scope->salesChannelIds($context);

        return new JsonResponse([
            'restricted' => $ids !== null,
            'salesChannelIds' => $ids ?? [],
            // Entry categories of the user's channels and of all others: a category
            // is editable when it lies in an own tree and in no other channel's tree.
            'categoryEntryPoints' => $ids === null ? ['own' => [], 'foreign' => []] : $this->categories->entryPointsFor($ids),
        ]);
    }
}
