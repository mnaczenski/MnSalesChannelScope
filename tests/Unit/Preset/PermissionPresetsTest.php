<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Preset;

use Mn\SalesChannelScope\Preset\PermissionPresets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionPresets::class)]
class PermissionPresetsTest extends TestCase
{
    private PermissionPresets $presets;

    protected function setUp(): void
    {
        $this->presets = new PermissionPresets(\dirname(__DIR__, 3) . '/src/Resources/config/presets');
    }

    public function testTheOrderPresetCoversTheOrderModule(): void
    {
        static::assertContains('orders', $this->presets->names());
        $privileges = $this->presets->privileges('orders');

        // admin roles the order module checks with acl.can()
        foreach (['order.viewer', 'order.editor', 'order.creator', 'order.deleter', 'orders.create_discounts', 'document.viewer', 'document.deleter', 'api_send_email'] as $key) {
            static::assertContains($key, $privileges);
        }
        // API routes the order module calls
        foreach (['order:update', 'order_transaction:update', 'order_delivery:update', 'order_address:update', 'document:read', 'document:create', 'document:update', 'document:delete', 'document_file:create', 'order_refund.editor'] as $privilege) {
            static::assertContains($privilege, $privileges);
        }
        static::assertSame($privileges, array_values(array_unique($privileges)));
    }

    public function testTheProductPresetCoversThePermissionsThePagePullsIn(): void
    {
        $privileges = $this->presets->privileges('products');

        // core product roles reference the media and Shopping Experience viewers through getPrivileges()
        foreach (['product.viewer', 'product.editor', 'product:update', 'media_folder:read', 'cms_page:read'] as $privilege) {
            static::assertContains($privilege, $privileges);
        }
        // Commercial extends the product roles (review summaries, bundles)
        foreach (['product_review_summary:read', 'product_review_summary_translation:read', 'bundle_item:read'] as $privilege) {
            static::assertContains($privilege, $privileges);
        }
    }

    public function testEveryAdditionalPrivilegeSaysWhyItIsNeeded(): void
    {
        foreach ($this->presets->load('orders')['additionalPrivileges'] as $privilege => $reason) {
            static::assertMatchesRegularExpression('/^[a-z_]+(:[a-z_]+)*$/', $privilege);
            static::assertNotSame('', trim($reason), $privilege);
        }
    }

    public function testUnknownPresetsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->presets->privileges('../secrets');
    }
}
