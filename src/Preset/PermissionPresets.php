<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Preset;

/**
 * Permission presets (src/Resources/config/presets/*.json): the admin roles
 * and additional privileges a module needs. The administration applies them on
 * the role page; the command mn:channel-scope:preset writes them directly.
 */
class PermissionPresets
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (string $file) => basename($file, '.json'), glob($this->directory . '/*.json') ?: []);
    }

    /**
     * Every privilege a role with this preset stores: the admin roles, the
     * privileges they expand to and the additional privileges.
     *
     * @return list<string>
     */
    public function privileges(string $name): array
    {
        $preset = $this->load($name);

        $privileges = [
            ...$preset['roles'],
            ...$preset['expandedPrivileges'],
            ...array_keys($preset['additionalPrivileges']),
        ];
        $privileges = array_values(array_unique($privileges));
        sort($privileges);

        return $privileges;
    }

    /**
     * @return array{name: string, roles: list<string>, additionalPrivileges: array<string, string>, expandedPrivileges: list<string>}
     */
    public function load(string $name): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $name) || !is_file($file = $this->directory . '/' . $name . '.json')) {
            throw new \InvalidArgumentException(\sprintf('Unknown preset "%s". Available: %s', $name, implode(', ', $this->names())));
        }

        /** @var array{name: string, roles: list<string>, additionalPrivileges: array<string, string>, expandedPrivileges: list<string>} $preset */
        $preset = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        return $preset;
    }
}
