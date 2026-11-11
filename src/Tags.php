<?php declare(strict_types=1);

namespace Mn\SalesChannelScope;

/**
 * Service tags of the plugin's extension points (see docs/extending.md).
 */
final class Tags
{
    /** Mn\SalesChannelScope\Rule\ReadRule implementations. */
    public const READ_RULE = 'mn_channel_scope.read_rule';

    /** Mn\SalesChannelScope\Rule\WriteRule implementations. */
    public const WRITE_RULE = 'mn_channel_scope.write_rule';

    /** Mn\SalesChannelScope\Preset\PresetDirectory services. */
    public const PRESET_DIRECTORY = 'mn_channel_scope.preset_directory';

    /** Mn\SalesChannelScope\Scope\SettingsDomains services. */
    public const SETTINGS_DOMAINS = 'mn_channel_scope.settings_domains';
}
