<?php

namespace W4OS\Grav;

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Config\Layout;
use W4OS\Helpers\View\View;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\SiteDescription;
use W4OS\Engine\Provisioning\GridConf;
use W4OS\Engine\Provisioning\Presets;
use W4OS\Engine\Provisioning\RemoteGridPlanner;
use W4OS\Engine\Runtime\Environment;

/**
 * The engine and the helpers the plugin bundles (its vendor folder), used as libraries: no package of the system, nothing read from
 * the install of the server, no folder of config. Its grid is made of what the engine of the grid's machine says of it for a site
 * (its API: GET /grids/{grid}/site-description), or described from its login address and the database of its Robust, written in a
 * folder of the site. Its databases are reached with the access the site gives (`database`), each with its own name; what it
 * writes is in the site's folders.
 */
final class Bundled
{
    private ?Environment $environment = null;

    private ?Router $router = null;

    private ?Router $public = null;

    private ?string $nick = null;

    /** Whether the last block made could not be made for now (View::unavailable()) */
    private bool $unavailable = false;

    /**
     * @param array<string,mixed> $settings login (the address of a grid it describes), database (host, port, name, user, password),
     *     grid
     * @param string $root the folder of the site where a described grid is written (etc/grids/<nick>)
     * @param \Closure(string $url): ?string $fetch the body at an address (get_grid_info of a described grid)
     * @param string $cache the folder of the caches of the engine for this site (the pictures it converts...), one the site writes to
     * @param string $store the file of the settings the site keeps for the engine (SettingsFile), read before the files of the grid
     * @param ?\Closure(): ?array<string,mixed> $describe what the engine of the grid's machine says of it for a site
     *     (GET /grids/{grid}/site-description), null when it cannot tell
     */
    public function __construct(private array $settings, private string $root, private \Closure $fetch, private string $cache = '', private string $store = '', private ?\Closure $describe = null) {}

    /** The grid, once made. */
    private ?Grid $grid = null;

    /** The libraries the plugin bundles (its vendor folder: the engine, the helpers), loaded once; whether the engine is there. */
    public static function library(): bool
    {
        static $loaded = null;
        if ($loaded === null) {
            $autoload = dirname(__DIR__).'/vendor/autoload.php';
            is_file($autoload) && require_once $autoload;
            $loaded = class_exists(\W4OS\Engine\Models\Grid::class);
        }

        return $loaded;
    }

    /** Whether the plugin has its vendor folder (the release zip, or composer install). */
    public static function available(): bool
    {
        return class_exists(Router::class) && class_exists(View::class);
    }

    /** The nick of the grid, as the engine knows it. */
    public function nick(): string
    {
        $this->grid();

        return (string) $this->nick;
    }

    /**
     * A call to the engine API, as the API answers it.
     *
     * @return array{int, string, array<string,string>} the status, the body, the headers
     */
    public function route(string $method, string $path, ?string $body = null): array
    {
        $this->router ??= new Router($this->api());
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $response = $this->router->route($method, (string) parse_url($path, PHP_URL_PATH), $query, (string) $body);

        return [$response->status, $response->body(), $response->headers];
    }

    /**
     * A request of the web to the engine (a picture of the grid): locked, the engine answers only what it says is public
     * (Router::PUBLIC), never the rest of its API, which the site asks in-process (route()).
     *
     * @return array{int, string, array<string,string>} the status, the body, the headers
     */
    public function publicRoute(string $method, string $path, ?string $body = null): array
    {
        $this->public ??= new Router($this->api(), authenticator: new \W4OS\Engine\Security\Locked());
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $response = $this->public->route($method, (string) parse_url($path, PHP_URL_PATH), $query, (string) $body);

        return [$response->status, $response->body(), $response->headers];
    }

    /**
     * Whether an address of the site is the helpers' (answers()): under the prefix of the helpers the site is set with, when the grid
     * sets a helper there (an address it gives, its config or its running grid; never one of the defaults of the engine).
     */
    public function serves(string $path, string $prefix): bool
    {
        $grid = $this->grid();
        if ($grid === null) {
            return false;
        }
        $configured = [];
        foreach ($grid->addresses(true) as $key => $url) {
            in_array($grid->service($key)?->source(), [null, 'default'], true) || $configured[] = $url;
        }

        return self::answers($path, $prefix, $configured);
    }

    /**
     * Whether the helpers the plugin bundles answer an address of the site: under the prefix of the helpers it is set with (the path
     * of `url`), when one of the addresses the grid sets for its helpers is under it; never the engine API under it. Any other
     * address is the site's (its pages, or its 404): the helpers never take a route that is not theirs.
     *
     * @param string $prefix the address or the path of the helpers the site is set with
     * @param list<string> $configured the addresses the grid sets for its services
     */
    public static function answers(string $path, string $prefix, array $configured): bool
    {
        $prefix = '/'.trim((string) parse_url($prefix, PHP_URL_PATH), '/');
        $under = fn (string $path) => $path === $prefix || str_starts_with($path, "$prefix/");
        if ($prefix === '/' || ! $under($path) || self::isApi($path, $prefix)) {
            return false;
        }
        foreach ($configured as $url) {
            if ($under('/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address of the site is the engine API under the base of the helpers (/helpers/api...): never theirs to answer, the
     * engine alone serves its API, at its own address.
     */
    public static function isApi(string $path, string $base): bool
    {
        $api = rtrim((string) parse_url($base, PHP_URL_PATH), '/').'/api';

        return $path === $api || str_starts_with($path, "$api/");
    }

    /** Answers the request of this process with the helpers of the grid (their Slim application), then does what waits for it. */
    public function serveHelpers(): void
    {
        \W4OS\Helpers\App::create(fn (string $nick) => $this->grid())->run();
        // What waits for the answer, as the helpers' own index.php does it: the answer goes first, then a simulator that registered
        // is read and the tasks that are due run (the search index fills by itself)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        method_exists(\W4OS\Helpers\Http\Action\Engine::class, 'finish') && \W4OS\Helpers\Http\Action\Engine::finish();
    }

    /**
     * The grid: made of what the engine of its machine says of it for a site (`describe`), else described from its login address
     * (`login`); never read from a folder of config. Null when neither tells it.
     */
    public function grid(): ?Grid
    {
        if ($this->grid !== null) {
            return $this->grid;
        }
        $description = $this->describe !== null ? ($this->describe)() : null;
        if (is_array($description) && trim((string) ($description['grid']['nick'] ?? '')) !== '') {
            $this->grid = SiteDescription::grid($this->environment(), $description);
            $this->nick = $this->grid->nick();

            return $this->grid;
        }
        $login = rtrim(trim((string) ($this->settings['login'] ?? '')), '/');
        if ($login === '') {
            return null;
        }
        $this->nick ??= $this->describe($login, $this->environment());

        return $this->grid = Grid::find($this->environment(), $this->nick);
    }

    /** The API of the engine for the grid of the site, whatever folder it would look in. */
    private function api(): Api
    {
        return new Api($this->environment(), config: static fn (Grid $grid) => $grid->config(), grids: fn (string $nick) => $nick === $this->nick() ? $this->grid() : null);
    }

    /**
     * The HTML of a block of the helpers, made here.
     *
     * @param array<string,scalar> $args
     */
    public function block(string $name, array $args = []): ?string
    {
        $grid = $this->grid();
        if ($grid === null || \W4OS\Helpers\Block\Blocks::name($name) === null) {
            return null;
        }
        $templates = \Composer\InstalledVersions::getInstallPath('w4os/w4os-helpers').'/templates';
        $view = new View($templates);
        $html = $view->block((string) \W4OS\Helpers\Block\Blocks::name($name), $grid, $args);
        $this->unavailable = method_exists($view, 'unavailable') && $view->unavailable();

        return $html;
    }

    /**
     * A block and the notices to the visitor its rendering gave (View::notify()), for the zone of messages of the site; one the
     * helpers could not make for now is a BlockUnavailable, with their reason.
     *
     * @param array<string,mixed> $args
     * @return ?array{html: string, notices: list<array{level: string, text: string}>}
     * @throws BlockUnavailable when the helpers could not make it for now
     */
    public function rendered(string $name, array $args = []): ?array
    {
        $html = $this->block($name, $args);
        $notices = method_exists(View::class, 'notices') ? View::notices() : [];
        // A block the helpers could not make for now (a database of the grid that cannot be reached): its reason, their error notice
        if ($this->unavailable) {
            $reasons = array_column(array_filter($notices, fn (array $notice) => ($notice['level'] ?? '') === 'error'), 'text');
            throw new BlockUnavailable($reasons !== [] ? implode(' ', $reasons) : 'the helpers cannot make it for now', Failure::status(503));
        }

        return $html === null ? null : ['html' => $html, 'notices' => $notices];
    }

    /**
     * The engine of the grid, from the site's own settings, never the install of the server: what it writes in the site's folders
     * (a described grid in its own etc), its databases with the access the site gives (`database`, DatabaseAccess), the settings it
     * keeps (`store`).
     */
    private function environment(): Environment
    {
        return $this->environment ??= Environment::system(new Layout([
            'EtcRoot' => "$this->root/etc",
            'VarRoot' => "$this->root/var",
            'DataRoot' => "$this->root/var/data",
            'TasksRoot' => "$this->root/var/cron",
            'CacheRoot' => $this->cache !== '' ? $this->cache : "$this->root/cache",
            'LogsRoot' => "$this->root/logs",
            'BackupRoot' => "$this->root/backups",
        ]), databases: $this->databaseAccess(), settings: $this->kept());
    }

    /** The settings the site keeps for the engine (`store`, SettingsFile), read before the files of the grid; null without a file. */
    private function kept(): ?SettingsFile
    {
        return $this->store === '' ? null : new SettingsFile($this->store);
    }

    /**
     * The access to the databases of the grid the site gives (its setting `database`: host, with its port or not, port, user,
     * password), applied to every database of the grid, each its own name; null when it gives none.
     */
    private function databaseAccess(): ?\W4OS\Engine\Data\DatabaseAccess
    {
        $db = (array) ($this->settings['database'] ?? []);
        $host = trim((string) ($db['host'] ?? ''));
        $port = isset($db['port']) && (string) $db['port'] !== '' ? (int) $db['port'] : null;
        if (preg_match('/^(.+):(\d+)$/', $host, $m) === 1) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }
        $user = trim((string) ($db['user'] ?? ''));
        if ($host === '' && $user === '') {
            return null;
        }

        return new \W4OS\Engine\Data\DatabaseAccess($host, $port, $user, (string) ($db['password'] ?? ''));
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
        $plan = function () use ($environment, $login, $scheme, &$said): array {
            return RemoteGridPlanner::with([
                'environment' => $environment,
                'says' => function (string $host, int $port) use ($scheme, &$said): array {
                    return $said = $this->says("$scheme://$host:$port");
                },
            ])->plan(['address' => $login] + (trim((string) ($this->settings['grid'] ?? '')) === '' ? [] : ['nick' => trim((string) $this->settings['grid'])]));
        };
        $planned = $plan();
        // A grid described from another login address (changed in the settings): described again, its cache dropped
        if ($planned['grid'] !== null && $this->forgetsOther($planned['grid'], rtrim($login, '/').'/')) {
            $planned = $plan();
        }
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
     * Whether the description of a grid was made here from another login address than this one (the login its Helpers.ini keeps),
     * then forgotten: its description and its cache, the folders of the site only. One made from this login, or never made here,
     * stays.
     */
    private function forgetsOther(Grid $grid, string $login): bool
    {
        $helpers = $grid->directory().'/Helpers.ini';
        $made = is_file($helpers) ? (string) (parse_ini_file($helpers, true, INI_SCANNER_RAW)['GridInfoService']['login'] ?? '') : '';
        if ($made === '' || $made === $login) {
            return false;
        }
        $ours = fn (string $path) => array_filter([$this->root, $this->cache], fn (string $base) => $base !== '' && str_starts_with($path, rtrim($base, '/').'/')) !== [];
        $description = $grid->directory().'/'.$grid->nick().'.conf';
        $ours($description) && is_file($description) && unlink($description);
        $cache = $grid->cacheDirectory();
        if ($cache !== '' && $ours($cache) && is_dir($cache)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($cache);
        }

        return true;
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
