<?php

namespace W4os\Grav;

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\DirectoryLayout;
use OpenSim\Config\Layout;
use OpenSim\Helpers\View\View;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/**
 * The engine and the helpers the plugin bundles (its vendor folder), used as libraries: no API to reach, no key, no package of
 * the system. The grid is either the one of an OpenSim install of this server (its opensim.conf), or a grid described by its
 * login address and the database of its Robust: the plugin writes its description in a folder of the site, the way a web host
 * without OpenSimulator gives the helpers what they need (a remote grid `<nick>.conf` and a `Helpers.ini`).
 */
final class Bundled
{
    private ?Environment $environment = null;

    private ?Router $router = null;

    private ?string $nick = null;

    /**
     * @param array<string,mixed> $settings login, database (host, port, name, user, password), opensim_conf, grid
     * @param string $root the folder of the site where a described grid is written (etc/grids/<nick>)
     * @param \Closure(string $url): ?string $fetch the body at an address (get_grid_info of a described grid)
     * @param string $cache the folder of the caches of the engine for this site (the pictures it converts...), one the site writes to
     */
    public function __construct(private array $settings, private string $root, private \Closure $fetch, private string $cache = '') {}

    /** Whether the plugin has its vendor folder (the release zip, or composer install). */
    public static function available(): bool
    {
        return class_exists(Router::class) && class_exists(View::class);
    }

    /** The nick of the grid, as the engine knows it. */
    public function nick(): string
    {
        $this->environment();

        return (string) $this->nick;
    }

    /**
     * A call to the engine API, as the API answers it.
     *
     * @return array{int, string, array<string,string>} the status, the body, the headers
     */
    public function route(string $method, string $path, ?string $body = null): array
    {
        $this->router ??= new Router(new Api($this->environment()));
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $response = $this->router->route($method, (string) parse_url($path, PHP_URL_PATH), $query, (string) $body);

        return [$response->status, $response->body(), $response->headers];
    }

    /**
     * Whether an address of the site is one the helpers of the grid serve: under the address of the helpers (`/helpers`), or one of
     * the web services the grid gives the viewers and the simulators (welcome, search, profiles, economy, offline messages...). The
     * config of the grid says them, as it does for w4os: its WebURL and its services must be the ones of the site.
     */
    public function serves(string $path): bool
    {
        $grid = $this->grid();
        if ($grid === null) {
            return false;
        }
        $addresses = array_merge([(string) $grid->helpersUrl()], array_values($grid->addresses(true)));
        foreach ($addresses as $url) {
            // The address of a profile has the name in it
            $prefix = rtrim((string) preg_replace('#/?\[AGENT_NAME\].*$#', '', (string) parse_url($url, PHP_URL_PATH)), '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, "$prefix/"))) {
                return true;
            }
        }

        return false;
    }

    /** Answers the request of this process with the helpers of the grid (their Slim application), and sends the answer. */
    public function serveHelpers(): void
    {
        \OpenSim\Helpers\App::create(fn (string $nick) => $this->grid())->run();
    }

    /** The grid, as the engine finds it. */
    private function grid(): ?Grid
    {
        return Grid::find($this->environment(), $this->nick());
    }

    /**
     * The HTML of a block of the helpers, made here.
     *
     * @param array<string,scalar> $args
     */
    public function block(string $name, array $args = []): ?string
    {
        $grid = $this->grid();
        if ($grid === null || \OpenSim\Helpers\Block\Blocks::name($name) === null) {
            return null;
        }
        $templates = \Composer\InstalledVersions::getInstallPath('magicoli/opensim-helpers').'/templates';

        return (new View($templates))->block((string) \OpenSim\Helpers\Block\Blocks::name($name), $grid, $args);
    }

    /** The engine of the grid: the layout of this server's install, or the folder of the described grid. */
    private function environment(): Environment
    {
        if ($this->environment !== null) {
            return $this->environment;
        }
        $login = rtrim(trim((string) ($this->settings['login'] ?? '')), '/');
        if ($login === '') {
            $conf = trim((string) ($this->settings['opensim_conf'] ?? ''));
            $layout = Layout::load($conf !== '' ? $conf : null, DirectoryLayout::roots());
            // The caches of the site's own: the ones of the install belong to its system user
            $this->environment = Environment::system($this->cache === '' ? $layout : new Layout(['CacheRoot' => $this->cache] + $layout->all(), $layout->file(), $layout->profile()));
            $this->nick = trim((string) ($this->settings['grid'] ?? '')) ?: $this->onlyGrid();

            return $this->environment;
        }
        $this->nick = $this->describe($login);
        $this->environment = Environment::system(new Layout([
            'EtcRoot' => "$this->root/etc",
            'VarRoot' => "$this->root/var",
            'DataRoot' => "$this->root/var/data",
            'CacheRoot' => $this->cache !== '' ? $this->cache : "$this->root/cache",
            'LogsRoot' => "$this->root/logs",
            'BackupRoot' => "$this->root/backups",
        ]));

        return $this->environment;
    }

    /** The nick of the only grid of this server's install. */
    private function onlyGrid(): string
    {
        $grids = Grid::all($this->environment)->items();

        return count($grids) === 1 ? $grids[0]->id() : '';
    }

    /**
     * Writes the description of a grid that runs elsewhere, from what its login address says (get_grid_info) and the database of
     * its Robust: `<nick>.conf` (a remote grid) and `Helpers.ini` (its database and its grid info, for the engine and the helpers).
     *
     * @return string the nick of the grid
     */
    private function describe(string $login): string
    {
        $xml = ($this->fetch)("$login/get_grid_info");
        $info = [];
        if (is_string($xml) && ($doc = @simplexml_load_string($xml)) !== false) {
            foreach ($doc->children() as $element) {
                $info[$element->getName()] = trim((string) $element);
            }
        }
        $nick = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($info['gridnick'] ?? ($this->settings['grid'] ?? ''))) ?: 'grid';
        $directory = "$this->root/etc/grids/$nick";
        $host = (string) parse_url($login, PHP_URL_HOST);
        $port = (int) (parse_url($login, PHP_URL_PORT) ?: 8002);
        $db = (array) ($this->settings['database'] ?? []);
        $connection = sprintf(
            'Data Source=%s;Port=%d;Database=%s;User ID=%s;Password=%s;Old Guids=true;SslMode=None;',
            $db['host'] ?? 'localhost',
            (int) ($db['port'] ?? 3306),
            $db['name'] ?? '',
            $db['user'] ?? '',
            $db['password'] ?? '',
        );
        $files = [
            "$nick.conf" => self::ini(['Grid' => [
                'Remote' => 'true',
                'GridName' => $info['gridname'] ?? $nick,
                'GridNick' => $nick,
                'BaseHostname' => $host,
                'PublicPort' => (string) $port,
                'PrivatePort' => (string) ($port + 1),
            ]]),
            'Helpers.ini' => self::ini([
                'DatabaseService' => ['StorageProvider' => 'OpenSim.Data.MySQL.dll', 'ConnectionString' => $connection],
                'GridInfoService' => $info + ['login' => "$login/"],
            ]),
        ];
        is_dir($directory) || mkdir($directory, 0750, true);
        foreach ($files as $name => $content) {
            $file = "$directory/$name";
            if (! is_file($file) || file_get_contents($file) !== $content) {
                file_put_contents($file, $content);
                chmod($file, 0640);
            }
        }

        return $nick;
    }

    /** @param array<string,array<string,string>> $sections */
    private static function ini(array $sections): string
    {
        $out = "; Written by the w4os plugin from its settings: changed there, not here\n";
        foreach ($sections as $section => $values) {
            $out .= "\n[$section]\n";
            foreach ($values as $key => $value) {
                $out .= "$key = \"".str_replace('"', '', (string) $value)."\"\n";
            }
        }

        return $out;
    }
}
