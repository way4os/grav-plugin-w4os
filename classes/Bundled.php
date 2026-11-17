<?php

namespace W4os\Grav;

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\DirectoryLayout;
use OpenSim\Config\Layout;
use OpenSim\Helpers\View\View;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridConf;
use OpenSim\Provisioning\Presets;
use OpenSim\Provisioning\RemoteGridPlanner;
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
     * Whether an address of the site is the helpers': under their base (`/helpers` by default), as the engine decides it
     * (Grid::servedByHelpers(), the path asked on the origin of the base). Every other address is the site's, the pages of the grid
     * it serves included. Helpers at the root of the site are a site of their own: nothing is theirs here.
     */
    public function serves(string $path): bool
    {
        $grid = $this->grid();
        $base = $grid?->helpersUrl();
        if ($base === null || trim((string) parse_url($base, PHP_URL_PATH), '/') === '') {
            return false;
        }
        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $grid->servedByHelpers($origin.$path);
    }

    /** Answers the request of this process with the helpers of the grid (their Slim application), then does what waits for it. */
    public function serveHelpers(): void
    {
        \OpenSim\Helpers\App::create(fn (string $nick) => $this->grid())->run();
        // What waits for the answer, as the helpers' own index.php does it: the answer goes first, then a simulator that registered
        // is read and the tasks that are due run (the search index fills by itself)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        method_exists(\OpenSim\Helpers\Http\Action\Engine::class, 'finish') && \OpenSim\Helpers\Http\Action\Engine::finish();
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

    /**
     * A block and the notices to the visitor its rendering gave (View::notify()), for the zone of messages of the site.
     *
     * @param array<string,mixed> $args
     * @return ?array{html: string, notices: list<array{level: string, text: string}>}
     */
    public function rendered(string $name, array $args = []): ?array
    {
        $html = $this->block($name, $args);
        $notices = method_exists(View::class, 'notices') ? View::notices() : [];

        return $html === null ? null : ['html' => $html, 'notices' => $notices];
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
            // The caches and the state of the tasks of the site's own (the ones of the described grids' place): the ones of the
            // install belong to its system user
            $own = ['TasksRoot' => "$this->root/var/cron"] + ($this->cache === '' ? [] : ['CacheRoot' => $this->cache]);
            $this->environment = Environment::system(new Layout($own + $layout->all(), $layout->file(), $layout->profile()));
            $this->nick = trim((string) ($this->settings['grid'] ?? '')) ?: $this->onlyGrid();

            return $this->environment;
        }
        $environment = Environment::system(new Layout([
            'EtcRoot' => "$this->root/etc",
            'VarRoot' => "$this->root/var",
            'DataRoot' => "$this->root/var/data",
            'CacheRoot' => $this->cache !== '' ? $this->cache : "$this->root/cache",
            'LogsRoot' => "$this->root/logs",
            'BackupRoot' => "$this->root/backups",
        ]));
        $this->nick = $this->describe($login, $environment);

        return $this->environment = $environment;
    }

    /** The nick of the only grid of this server's install. */
    private function onlyGrid(): string
    {
        $grids = Grid::all($this->environment)->items();

        return count($grids) === 1 ? $grids[0]->id() : '';
    }

    /**
     * Writes the description of a grid that runs elsewhere, as the engine plans it from its login address (RemoteGridPlanner: what
     * the grid says of itself, its nick checked, never changed): `<nick>.conf` (a remote grid, GridConf) and `Helpers.ini` (the
     * database of its Robust and its grid info, for the engine and the helpers).
     *
     * @return string the nick of the grid
     * @throws EngineException when the engine refuses the grid (a nick that is not one, the one of a grid of this machine)
     */
    private function describe(string $login, Environment $environment): string
    {
        $scheme = (string) (parse_url($login, PHP_URL_SCHEME) ?: 'http');
        $said = [];
        $planned = RemoteGridPlanner::with([
            'environment' => $environment,
            'says' => function (string $host, int $port) use ($scheme, &$said): array {
                return $said = $this->says("$scheme://$host:$port");
            },
        ])->plan(['address' => $login] + (trim((string) ($this->settings['grid'] ?? '')) === '' ? [] : ['nick' => trim((string) $this->settings['grid'])]));
        if ($planned['error'] !== null || $planned['grid'] === null) {
            throw new EngineException(503, (string) ($planned['error'] ?? 'The grid cannot be described.'), ['code' => 'channel-unavailable']);
        }
        $grid = $planned['grid']->describe();
        (new GridConf())->writeRemote($grid, new Presets([], $environment->layout->etcRoot()));
        $directory = (string) $grid['directory'];
        $db = (array) ($this->settings['database'] ?? []);
        $port = (int) ($db['port'] ?? 3306);
        $source = (string) ($db['host'] ?? 'localhost').($port === 3306 ? '' : ":$port");
        $connection = (string) ConnectionString::for($source, (string) ($db['name'] ?? ''), (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''));
        $files = [
            'Helpers.ini' => self::ini([
                'DatabaseService' => ['StorageProvider' => 'OpenSim.Data.MySQL.dll', 'ConnectionString' => $connection],
                'GridInfoService' => $said + ['login' => rtrim($login, '/').'/'],
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

        return (string) $grid['nick'];
    }

    /**
     * What a grid says of itself (get_grid_info at its address), empty when it does not answer.
     *
     * @return array<string,string>
     */
    private function says(string $address): array
    {
        $xml = ($this->fetch)(rtrim($address, '/').'/get_grid_info');
        $info = [];
        if (is_string($xml) && ($doc = @simplexml_load_string($xml)) !== false) {
            foreach ($doc->children() as $element) {
                $info[$element->getName()] = trim((string) $element);
            }
        }

        return $info;
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
