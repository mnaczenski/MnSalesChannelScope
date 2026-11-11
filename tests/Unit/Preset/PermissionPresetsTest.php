<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Preset;

use Mn\SalesChannelScope\Preset\PermissionPresets;
use Mn\SalesChannelScope\Preset\PresetDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionPresets::class)]
#[CoversClass(PresetDirectory::class)]
class PermissionPresetsTest extends TestCase
{
    private const CORE = __DIR__ . '/../../../src/Resources/config/presets';
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private PermissionPresets $presets;

    protected function setUp(): void
    {
        $this->presets = new PermissionPresets([new PresetDirectory(self::CORE)]);
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

    public function testEveryShippedPresetIsValidAndOrdered(): void
    {
        static::assertSame(['orders', 'products', 'categories', 'marketing', 'cms', 'settings'], $this->presets->names());

        foreach ($this->presets->all() as $preset) {
            static::assertNotSame('', $preset['label']['en-GB'] ?? '', $preset['name']);
            static::assertNotSame('', $preset['label']['de-DE'] ?? '', $preset['name']);
            static::assertNotSame('', $preset['description']['en-GB'] ?? '', $preset['name']);
            static::assertNotSame('', $preset['description']['de-DE'] ?? '', $preset['name']);
            static::assertNotEmpty($preset['roles'], $preset['name']);
            // expanded privileges are entity privileges (product:read) or standalone
            // ones (api_proxy_switch-customer), never admin role keys (product.viewer)
            foreach ($preset['expandedPrivileges'] as $privilege) {
                static::assertMatchesRegularExpression('/^[a-z0-9_:-]+$/', $privilege, $preset['name']);
            }
        }
    }

    public function testOtherDirectoriesAddPresets(): void
    {
        $presets = new PermissionPresets([new PresetDirectory(self::CORE), new PresetDirectory(self::FIXTURES . '/presets-extra')]);

        static::assertContains('acme-newsletter', $presets->names());
        static::assertContains('newsletter_recipient:update', $presets->privileges('acme-newsletter'));
        // an existing name in a lower priority directory is ignored
        static::assertSame('Order management', $presets->load('orders')['label']['en-GB']);
    }

    public function testInvalidPresetFilesAreReported(): void
    {
        $presets = new PermissionPresets([new PresetDirectory(self::FIXTURES . '/presets-invalid')]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('"name" must be the file name');
        $presets->load('broken');
    }
}
