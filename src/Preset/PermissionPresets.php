<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Preset;

/**
 * Permission presets: the admin roles and additional privileges a module
 * needs, read from the preset directories (PresetDirectory). The
 * administration lists and applies them on the role page; the command
 * mn:channel-scope:preset writes them directly.
 *
 * A preset file (<name>.json):
 *
 *     {
 *         "name": "orders",                     // = file name
 *         "position": 10,                       // order on the role page
 *         "label": {"en-GB": "...", "de-DE": "..."},
 *         "description": {"en-GB": "...", "de-DE": "..."},
 *         "roles": ["order.viewer", ...],       // admin roles to tick
 *         "additionalPrivileges": {"document:delete": "why it is needed"},
 *         "expandedPrivileges": ["order:read", ...]  // what the roles expand to (bin/expand-preset.js)
 *     }
 *
 * @phpstan-type Preset array{name: string, position: int, label: array<string, string>, description: array<string, string>, roles: list<string>, additionalPrivileges: array<string, string>, expandedPrivileges: list<string>}
 */
class PermissionPresets
{
    private const NAME = '/^[a-z0-9_-]+$/';

    /** @var array<string, string>|null name => file */
    private ?array $files = null;

    /**
     * @param iterable<PresetDirectory> $directories highest priority first
     */
    public function __construct(private readonly iterable $directories)
    {
    }

    /**
     * @return list<string> ordered by position
     */
    public function names(): array
    {
        return array_map(static fn (array $preset) => $preset['name'], $this->all());
    }

    /**
     * Every preset, ordered by position (then name).
     *
     * @return list<Preset>
     */
    public function all(): array
    {
        $presets = array_map(fn (string $name) => $this->load($name), array_keys($this->files()));
        usort($presets, static fn (array $a, array $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);

        return $presets;
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

        $privileges = array_values(array_unique([
            ...$preset['roles'],
            ...$preset['expandedPrivileges'],
            ...array_keys($preset['additionalPrivileges']),
        ]));
        sort($privileges);

        return $privileges;
    }

    /**
     * @return Preset
     */
    public function load(string $name): array
    {
        $file = preg_match(self::NAME, $name) ? ($this->files()[$name] ?? null) : null;
        if ($file === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown preset "%s". Available: %s', $name, implode(', ', array_keys($this->files()))));
        }

        $preset = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        return $this->validate($name, $file, \is_array($preset) ? $preset : []);
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $this->files = [];
        foreach ($this->directories as $directory) {
            foreach (glob(rtrim($directory->path, '/') . '/*.json') ?: [] as $file) {
                $name = basename($file, '.json');
                if (preg_match(self::NAME, $name) && !isset($this->files[$name])) {
                    $this->files[$name] = $file;
                }
            }
        }

        return $this->files;
    }

    /**
     * @param array<mixed> $preset
     *
     * @return Preset
     */
    private function validate(string $name, string $file, array $preset): array
    {
        $invalid = static fn (string $what) => new \UnexpectedValueException(\sprintf('Invalid preset %s: %s', $file, $what));

        if (($preset['name'] ?? null) !== $name) {
            throw $invalid('"name" must be the file name');
        }
        foreach (['roles', 'expandedPrivileges'] as $key) {
            if (!array_is_list($preset[$key] ?? null) || array_filter($preset[$key], static fn ($value) => !\is_string($value)) !== []) {
                throw $invalid(\sprintf('"%s" must be a list of strings', $key));
            }
        }
        foreach (['label', 'description', 'additionalPrivileges'] as $key) {
            if (!\is_array($preset[$key] ?? null)) {
                throw $invalid(\sprintf('"%s" must be an object', $key));
            }
        }
        if (($preset['label']['en-GB'] ?? '') === '') {
            throw $invalid('"label" needs an "en-GB" text');
        }

        $preset['position'] = (int) ($preset['position'] ?? 1000);

        /** @var Preset $preset */
        return $preset;
    }
}
