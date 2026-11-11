<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Preset;

/**
 * A directory of permission preset files (*.json). Register one as a service
 * with the tag Tags::PRESET_DIRECTORY ("mn_channel_scope.preset_directory") to add presets:
 *
 *     $services->set('acme.scope.presets', PresetDirectory::class)
 *         ->args([__DIR__ . '/../presets'])
 *         ->tag(Tags::PRESET_DIRECTORY);
 *
 * A preset whose name already exists in a directory with a higher tag
 * priority is ignored (the plugin's own directory has priority 100).
 */
final class PresetDirectory
{
    public function __construct(public readonly string $path)
    {
    }
}
