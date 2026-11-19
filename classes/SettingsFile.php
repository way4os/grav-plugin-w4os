<?php

namespace W4os\Grav;

use OpenSim\Config\SettingsStore;

/**
 * The settings the site keeps for the engine it bundles (the key of the money, the addresses of its services, what its settings
 * pages change for a grid whose files it cannot write), in a file of its data only its web server reads, as its database access.
 */
final class SettingsFile implements SettingsStore
{
    /** @var ?array<string,array<string,string>> */
    private ?array $settings = null;

    public function __construct(private string $file) {}

    public function all(): array
    {
        if ($this->settings === null) {
            $read = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
            $this->settings = is_array($read) ? array_map(fn ($section) => array_map('strval', (array) $section), $read) : [];
        }

        return $this->settings;
    }

    public function writable(): bool
    {
        return is_file($this->file) ? is_writable($this->file) : is_writable($this->existing(dirname($this->file)));
    }

    public function set(string $section, string $key, ?string $value): void
    {
        $settings = $this->all();
        if ($value === null) {
            unset($settings[$section][$key]);
        } else {
            $settings[$section][$key] = $value;
        }
        $this->settings = $settings;
        is_dir(dirname($this->file)) || mkdir(dirname($this->file), 0750, true);
        // Written whole, then put in place, readable by the web server alone
        $temporary = $this->file.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));
        chmod($temporary, 0640);
        rename($temporary, $this->file);
    }

    /** The folder of a path that exists, the path itself or the nearest of its parents. */
    private function existing(string $folder): string
    {
        while (! is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }

        return $folder;
    }
}
