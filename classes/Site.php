<?php

namespace W4OS\Grav;

use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Page\Medium\ImageMedium;
use Grav\Common\Page\Medium\MediumFactory;
use Grav\Common\Page\Page;
use Grav\Common\User\Interfaces\UserInterface;
use W4OS\Engine\Http\Url;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Views\Access;
use W4OS\Views\Contract;

/**
 * The part that knows Grav: the settings and the secrets, the engine (the API of the grid through Grav's HTTP client, or the engine
 * the plugin bundles) and the blocks with Grav's cache, the pages of the plugin, the avatar of the visitor.
 */
final class Site
{
    /** The asset the engine answers with its placeholder picture. */
    /** Where the API key typed on the settings page is kept, out of the configuration. */
    public const SECRET = 'user-data://w4os/api.json';

    /** Where the password of the database of a described grid is kept, out of the configuration. */
    public const DATABASE_SECRET = 'user-data://w4os/database.json';

    /**
     * An area (m²) in the unit the engine says it in (km², two decimals under 10, none above): the key of its unit in the catalog
     * and its number, in the language of the page.
     *
     * @return array{0: string, 1: string}
     */
    public static function areaText(float $m2, string $language = 'en'): array
    {
        [$value, $unit, $decimals] = \W4OS\Engine\Text\Units::area($m2);

        return [$unit, self::number($value, $language, $decimals)];
    }

    /** A number in the language of the page (fr: 15 565, 6,55), at most `decimals` decimals, by the formatter of PHP (Intl). */
    public static function number(float $value, string $language = 'en', int $decimals = 2): string
    {
        if (! class_exists(\NumberFormatter::class)) {
            return rtrim(rtrim(number_format($value, $decimals), '0'), '.');
        }
        $formatter = new \NumberFormatter(str_replace('-', '_', $language), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->format($value);
    }

    /**
     * A time for a page of the admin: a `<time>` with its ISO moment and its style (W4OS\Views\Dates), told by the server in its own
     * zone, its full date and its zone as its tip; the script of the admin tells it again in the time and the language of the
     * browser (js/dates.js). A time in a list stays relative while recent (`5 minutes ago`), its zone said once by the list
     * (zoneTag()); a time of its own (`zoned`: a banner, a header, a detail) is a date and an hour, its zone after them
     * (`25/11/2026 20:37 UTC`).
     *
     * @param int|string|null $value a timestamp or a date PHP reads
     */
    public static function timeTag(int|string|null $value, string $language, string $style = 'list', ?int $now = null, bool $zoned = false): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return '';
        }
        try {
            $date = (is_numeric($value) ? (new \DateTimeImmutable)->setTimestamp((int) $value) : new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return htmlspecialchars((string) $value);
        }
        $now ??= time();
        $relative = ! $zoned && $style === 'list' && abs($now - $date->getTimestamp()) < \W4OS\Views\Dates::RECENT && \W4OS\Views\Dates::canTellRelative();
        $zone = ' '.$date->format('T');
        // A time of its own as a date and an hour, never relative
        $text = \W4OS\Views\Dates::format($date->getTimestamp(), $language, $style, $zoned ? $date->getTimestamp() + \W4OS\Views\Dates::RECENT : $now)
            .($zoned && $style !== 'date' ? $zone : '');
        $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES);

        return '<time datetime="'.$e($date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')).'" data-w4os-time="'.$e($style).'"'
            .($relative ? ' data-w4os-relative' : '').($zoned ? ' data-w4os-zoned' : '').' title="'.$e(\W4OS\Views\Dates::format($date->getTimestamp(), $language, 'full', $now).$zone).'">'
            .$e($text).'</time>';
    }

    /** The zone the times of a list are in, said once for them all: the server's (`UTC`), the browser's once its script ran (js/dates.js). */
    public static function zoneTag(?int $now = null): string
    {
        $zone = (new \DateTimeImmutable('@'.($now ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('T');

        return '<span data-w4os-zone>'.htmlspecialchars($zone).'</span>';
    }

    /**
     * What the health of the grid says, in the language of the page: each text of a line the engine gives as a sentence of its
     * catalog (`texts`: by field, its key and the values of its placeholders) said by `translate`, the others as they are.
     *
     * @param array<string,mixed> $health
     * @param \Closure(string $key, array $params): string $translate
     * @return array<string,mixed>
     */
    public static function healthSaid(array $health, \Closure $translate): array
    {
        foreach ((array) ($health['groups'] ?? []) as $g => $group) {
            foreach ((array) ($group['rows'] ?? []) as $r => $row) {
                $health['groups'][$g]['rows'][$r] = self::rowSaid((array) $row, $translate);
            }
        }

        return $health;
    }

    /**
     * One line of the health in the language of the page (healthSaid()).
     *
     * @param array<string,mixed> $row
     * @param \Closure(string $key, array $params): string $translate
     * @return array<string,mixed>
     */
    public static function rowSaid(array $row, \Closure $translate): array
    {
        foreach ((array) ($row['texts'] ?? []) as $field => $text) {
            $key = 'w4os.'.(string) ($text['key'] ?? '');
            $said = $translate($key, array_values((array) ($text['params'] ?? [])));
            $said !== '' && $said !== $key && $row[$field] = $said;
        }

        return $row;
    }

    /** What the engine of the grid's machine says of it for the helpers of the site, kept with its time (keptDescription()). */
    public const SITE_DESCRIPTION = 'user-data://w4os/site-description.json';

    /** The settings the site keeps for its bundled engine (SettingsFile): the key of the money, what its settings pages change. */
    public const ENGINE_SETTINGS = 'user-data://w4os/engine-settings.json';

    /** Where the description of a grid the bundled engine works with is written. */
    public const GRID_FOLDER = 'user-data://w4os/opensim';

    private ?Engine $engine = null;

    private ?Bundled $bundled = null;

    public function __construct(private Grav $grav) {}

    /** @return array<string,mixed> the settings of the plugin */
    public function config(): array
    {
        return (array) $this->grav['config']->get('plugins.w4os', []);
    }

    /**
     * The style of the backdrop of the site for this request (Backdrop): an image of the series of each mode at random, the site's
     * own else the active theme's (its parents' too), served by Grav's image media at the widths and in the formats Backdrop
     * gives; '' when the site shows none.
     */
    public function backdropCss(): string
    {
        $config = (array) ($this->config()['backdrop'] ?? []);
        if (! ($config['enabled'] ?? true)) {
            return '';
        }
        $images = [];
        foreach (array_keys(Backdrop::FOLDERS) as $mode) {
            $folder = $this->grav['locator']->findResource('theme://'.Backdrop::THEME_FOLDER."/$mode", false);
            $theme = is_string($folder) ? Backdrop::themeSeries(GRAV_ROOT, $folder) : [];
            $file = Backdrop::pick(Backdrop::series((array) ($config[$mode] ?? []), $theme, fn (string $path) => is_file(GRAV_ROOT.'/'.$path)));
            $file === null || $images[$mode] = $this->backdropImage(GRAV_ROOT.'/'.$file);
        }

        return Backdrop::css($images);
    }

    /**
     * An image of the backdrop as Grav serves it, resized and converted in its cache of images: by width, then format, its address.
     *
     * @return array<int,array<string,string>>
     */
    private function backdropImage(string $file): array
    {
        $size = @getimagesize($file);
        $images = [];
        foreach ($size ? Backdrop::widths((int) $size[0]) : [] as $width => $query) {
            foreach (array_keys(Backdrop::FORMATS) as $format) {
                $medium = MediumFactory::fromFile($file);
                if (! $medium instanceof ImageMedium) {
                    return [];
                }
                $images[$width][$format] = $medium->cropResize($width, $width)->format($format)->quality(Backdrop::QUALITY)->url();
            }
        }

        return $images;
    }

    /**
     * Which engine the site asks: the API of the engine whenever it has its address and key (only the engine touches the configs of
     * the grid; the engine the plugin bundles then serves the helpers alone), else the bundled one (a site that describes its grid).
     *
     * @param array<string,mixed> $config the settings of the plugin
     * @return 'api'|'bundled'
     */
    public static function engineChannel(array $config, bool $hasKey): string
    {
        $bundled = ($config['engine'] ?? 'remote') === 'bundled';

        return ! $bundled || (self::apiUrl($config) !== '' && $hasKey) ? 'api' : 'bundled';
    }

    /**
     * The engine: the API of the grid (its address and key, engineChannel()), or the engine the plugin bundles.
     */
    public function engine(): Engine
    {
        if ($this->engine !== null) {
            return $this->engine;
        }
        if (self::engineChannel($this->config(), $this->apiKey() !== '') === 'bundled' && ($bundled = $this->bundled())) {
            return $this->engine = new Engine('', '', $bundled->nick(), fn ($method, $url, $headers, $body) => array_slice($bundled->route($method, $url, $body), 0, 2), true, $this->path('assets'));
        }
        $config = $this->config();

        return $this->engine = new Engine(self::apiUrl($config), $this->apiKey(), trim((string) ($config['grid'] ?? '')), $this->send(...), assets: self::picturesUrl($config));
    }

    /**
     * The public address of the pictures of the grid with a remote engine: the helpers' (/pictures), the engine API being for the
     * site only (often on 127.0.0.1); empty without the address of the helpers.
     */
    public static function picturesUrl(array $config): string
    {
        $helpers = rtrim(trim((string) ($config['url'] ?? '')), '/');

        return $helpers === '' ? '' : "$helpers/pictures";
    }

    /**
     * The health of the grid for its page: checked again when asked (`refresh`), else the last report the engine kept; a check that
     * could not run again says why (`refreshError`) beside the last report, whose time tells how old it is.
     *
     * @param \Closure(bool $refresh): array<string,mixed> $read the health as the engine gives it
     * @return array{health: ?array<string,mixed>, error: ?string, refreshError: ?string}
     */
    public static function healthReport(\Closure $read, bool $refresh): array
    {
        $refreshError = null;
        if ($refresh) {
            try {
                return ['health' => $read(true), 'error' => null, 'refreshError' => null];
            } catch (\Throwable $e) {
                $refreshError = $e->getMessage();
            }
        }
        try {
            return ['health' => $read(false), 'error' => null, 'refreshError' => $refreshError];
        } catch (\Throwable $e) {
            return ['health' => null, 'error' => $e->getMessage(), 'refreshError' => $refreshError];
        }
    }

    /**
     * The addresses of a grid (the engine's /addresses: services) in the order of their settings, as its health page lists them: by
     * file (none last), section, then setting (its key without one).
     *
     * @param array<string,mixed>|null $addresses
     * @return array<string,mixed>|null
     */
    public static function addressesByPlace(?array $addresses): ?array
    {
        if (! is_array($addresses['services'] ?? null)) {
            return $addresses;
        }
        $place = fn (array $service): string => ($service['file'] ?? '~').($service['section'] ?? '').($service['setting'] ?? $service['key'] ?? '');
        usort($addresses['services'], fn ($a, $b) => $place((array) $a) <=> $place((array) $b));

        return $addresses;
    }

    /** The tasks of the engine a settings page of a service shows beside its form, by their name: the search's parsers. */
    public const SERVICE_TASKS = ['search' => ['search-parse', 'events-parse']];

    /**
     * The tasks a settings page of a service shows (SERVICE_TASKS), of the grid of the site, from the engine's tasks.
     *
     * @param list<array<string,mixed>> $tasks
     * @return list<array<string,mixed>>
     */
    public static function serviceTasks(array $tasks, string $grid, string $group): array
    {
        $names = self::SERVICE_TASKS[$group] ?? [];

        return array_values(array_filter($tasks, fn (array $task) => ($task['scope'] ?? '') === $grid && in_array($task['name'] ?? '', $names, true)));
    }

    /** Whether the visitor may run a task of the engine now, as the contract says (runTask). */
    public function canRunTask(): bool
    {
        return $this->isManager() && Access::allows($this->openApi()->access('post', '/tasks/{id}/runs'), $this->level());
    }

    /** The block of the helpers a page of the plugin shows (`w4os_block` of pages/<type>.md: guide, destination_guide), null for none. */
    public static function blockOfPage(string $type): ?string
    {
        $file = dirname(__DIR__)."/pages/$type.md";
        if (! preg_match('/^[a-z_]+$/', $type) || ! is_file($file) || ! preg_match('/^---\R(.*?)\R---/s', (string) file_get_contents($file), $matter)) {
            return null;
        }
        $block = \Symfony\Component\Yaml\Yaml::parse($matter[1])['w4os_block'] ?? null;

        return is_string($block) && $block !== '' ? $block : null;
    }

    /** Whether the text of a page shows a block of the helpers by its shortcode ([destination-guide]). */
    public static function holdsBlock(string $content, string $block): bool
    {
        return in_array(str_replace('-', '_', $block), self::blocksIn($content), true);
    }

    /**
     * The blocks of the helpers a text shows by their shortcodes (BLOCK_SHORTCODES: [grid-status], [avatar-profile name="A.B"]), by
     * their names in the helpers (grid_status), in their order, once each.
     *
     * @return list<string>
     */
    public static function blocksIn(string $text): array
    {
        $names = implode('|', array_map(fn (string $name) => preg_quote($name, '/'), array_keys(self::BLOCK_SHORTCODES)));
        preg_match_all('/\[('.$names.')(?=[\s\/\]])/', $text, $found);

        return array_values(array_unique(array_map(fn (string $name) => self::BLOCK_SHORTCODES[$name], $found[1])));
    }

    /** The levels of a row of the health that are a warning: not as it should be, but answering (slow among them). */
    public const HEALTH_WARNINGS = ['warning', 'slow'];

    /**
     * The problems the health of the grid found, for its banner and the notice of the dashboard: how many errors (`count`), how many
     * warnings (`warnings`), the labels of the first errors, else of the first warnings.
     *
     * @param array<string,mixed> $health the health as the engine gives it
     * @return array{count: int, warnings: int, labels: list<string>, statuses: list<string>}
     */
    public static function healthProblems(array $health, int $shown = 5): array
    {
        $found = ['error' => [], 'warning' => []];
        foreach ((array) ($health['groups'] ?? []) as $group) {
            foreach ((array) ($group['rows'] ?? []) as $row) {
                $level = (string) ($row['level'] ?? '');
                $kind = $level === 'error' ? 'error' : (in_array($level, self::HEALTH_WARNINGS, true) ? 'warning' : null);
                $kind === null || $found[$kind][] = [(string) ($row['label'] ?? ''), (string) ($row['status'] ?? '')];
            }
        }
        $first = array_slice($found['error'] !== [] ? $found['error'] : $found['warning'], 0, $shown);

        return ['count' => count($found['error']), 'warnings' => count($found['warning']), 'labels' => array_column($first, 0), 'statuses' => array_column($first, 1)];
    }

    /**
     * What the problems of the health say: "3 errors, 5 warnings" (the plural of each language), or that there is none.
     *
     * @param \Closure(string $key, int $count): string $say a text of the catalog with its number
     */
    public static function healthCounts(int $errors, int $warnings, \Closure $say): string
    {
        $parts = [];
        $errors > 0 && $parts[] = $say($errors === 1 ? 'w4os.admin_health_errors_one' : 'w4os.admin_health_errors_other', $errors);
        $warnings > 0 && $parts[] = $say($warnings === 1 ? 'w4os.admin_health_warnings_one' : 'w4os.admin_health_warnings_other', $warnings);

        return $parts === [] ? $say('w4os.admin_health_none', 0) : implode(', ', $parts);
    }

    /**
     * The status of the page of a profile the engine did not give: unavailable for now when the grid cannot be reached (503), else
     * a profile that does not exist (404).
     */
    public static function profileStatus(?FormError $error): int
    {
        return $error?->key === 'w4os.error_channel_unavailable' ? 503 : 404;
    }

    /** The address of the engine API of a remote engine: its setting `api_url`, never guessed from the helpers'; empty without it. */
    public static function apiUrl(array $config): string
    {
        return trim((string) ($config['api_url'] ?? ''));
    }

    /** The engine and the helpers the plugin bundles, when the settings ask them and the vendor folder is there; null otherwise. */
    public function bundled(): ?Bundled
    {
        if ($this->bundled !== null || ($this->config()['engine'] ?? 'remote') !== 'bundled') {
            return $this->bundled;
        }
        Bundled::library();
        if (! Bundled::available()) {
            $this->grav['log']->error('w4os: the engine is set to bundled, but the plugin has no vendor folder (the release zip has one)');

            return null;
        }
        $settings = (array) ($this->config()['bundled'] ?? []);
        $settings['grid'] = (string) ($this->config()['grid'] ?? '');
        $settings['database']['password'] = (string) ($settings['database']['password'] ?? '') ?: $this->secret(self::DATABASE_SECRET, 'password');
        $root = (string) $this->grav['locator']->findResource(self::GRID_FOLDER, true, true);

        $cache = (string) $this->grav['locator']->findResource('cache://w4os/opensim', true, true);

        $data = (string) $this->grav['locator']->findResource('user-data://w4os', true, true);
        // The grid of the helpers: what the engine of its machine says of it, through its API, when the site has one; else the one it
        // describes from its login address
        $config = $this->config();
        $describe = self::apiUrl($config) !== '' && $this->apiKey() !== ''
            ? fn () => self::keptDescription($data.'/'.basename(self::SITE_DESCRIPTION), fn () => $this->apiEngine()->siteDescription(), max(60, (int) ($config['cache'] ?? 3600)), time())
            : null;

        return $this->bundled = new Bundled($settings, $root, fn (string $url) => $this->get($url, 'text/xml'), $cache, $data.'/'.basename(self::ENGINE_SETTINGS), $describe);
    }

    /**
     * The description of the grid for the helpers of the site, kept in its file with the time the engine checked it: asked again
     * once it is older than `maxAge` seconds; the one kept when the engine cannot tell; null when there is none.
     *
     * @param \Closure(): ?array<string,mixed> $ask the engine's description
     * @return ?array<string,mixed>
     */
    public static function keptDescription(string $file, \Closure $ask, int $maxAge, int $now): ?array
    {
        $kept = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $kept = is_array($kept) ? $kept : null;
        $checked = $kept === null ? false : strtotime((string) ($kept['checkedAt'] ?? ''));
        if ($kept !== null && $checked !== false && $now - $checked < $maxAge) {
            return $kept;
        }
        try {
            $given = $ask();
        } catch (\Throwable) {
            $given = null;
        }
        if (! is_array($given)) {
            return $kept;
        }
        is_dir(dirname($file)) || mkdir(dirname($file), 0750, true);
        $temporary = $file.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode($given, JSON_UNESCAPED_SLASHES));
        chmod($temporary, 0640);
        rename($temporary, $file);

        return $given;
    }

    /** The API of the engine of the grid (its address and key), whatever engine the site asks. */
    private function apiEngine(): Engine
    {
        $config = $this->config();

        return new Engine(self::apiUrl($config), $this->apiKey(), trim((string) ($config['grid'] ?? '')), $this->send(...), assets: self::picturesUrl($config));
    }

    /** Whether the site serves the helpers of the grid itself (setting "serve_helpers", with the bundled engine). */
    public function servesHelpers(): bool
    {
        return (bool) ($this->config()['serve_helpers'] ?? false) && $this->bundled() !== null;
    }

    /**
     * Answers a request for the helpers of the grid, when the site serves them and the address is one of theirs (under the prefix of
     * its setting `url`, Bundled::answers()); false otherwise.
     */
    public function helpers(string $path): bool
    {
        if (! $this->servesHelpers() || ! $this->bundled()->serves($path, trim((string) ($this->config()['url'] ?? '')))) {
            return false;
        }
        $this->bundled()->serveHelpers();

        return true;
    }

    /**
     * Answers the address of a picture of the grid with the engine: under the web assets of the grid when the site provides them (the
     * engine's settings `assets`, their path), else under the route "assets" with the bundled engine (the pictures of the models).
     *
     * @return ?array{int, string, array<string,string>} the status, the body, the headers; null when it is not such an address
     */
    public function asset(string $path): ?array
    {
        $provided = $this->assetsRoute();
        // The path of the settings is on the site; Grav has its requests without its own root (a mini site under /opensim)
        $under = $provided === null ? null : self::routeUnder($provided, $this->grav['uri']->rootUrl(false));
        if ($provided !== null && $under === null) {
            return null;
        }
        $route = $under ?? ($this->routes()['assets'] ?? '');
        $asked = $route === '' ? null : self::assetAsked($route, $path);
        // The bundled engine when the site talks to it, else the engine of the grid (a site has the bundled library either way)
        $bundled = $asked === null || self::engineChannel($this->config(), $this->apiKey() !== '') !== 'bundled' ? null : $this->bundled();
        if ($asked === null || ($bundled === null && $provided === null)) {
            return null;
        }
        [$id, $format] = $asked;
        // From the web: the bundled engine locked, it answers what it says is public only; else the engine of the grid, its open operation
        if ($bundled !== null) {
            return $bundled->publicRoute('GET', '/grids/'.rawurlencode($bundled->nick())."/assets/$id.$format");
        }
        return $this->engine()->picture($id, $format);
    }

    /** Whether the grid has a web map, as the engine says (Grid::hasMap(), the API's GET /map), kept an hour; none when it does not answer. */
    public function hasMap(): bool
    {
        if (self::engineChannel($this->config(), $this->apiKey() !== '') === 'bundled') {
            $grid = $this->bundled()?->grid();

            return $grid !== null && method_exists($grid, 'hasMap') && $grid->hasMap();
        }
        $has = $this->cached('has map', function () {
            try {
                return $this->engine()->hasMap() ? 'yes' : 'no';
            } catch (\Throwable) {
                return 'no';
            }
        }, 3600);

        return $has === 'yes';
    }

    /**
     * Answers the address of a tile of the map of the grid (the block map) with the engine: under the route "map_tiles", its zoom (1
     * to 8) and the region of its bottom left corner (`/grid-map/1/1000/1000.jpg`).
     *
     * @return ?array{int, string, array<string,string>} the status, the body, the headers; null when it is not such an address
     */
    public function mapTile(string $path): ?array
    {
        $asked = self::tileAsked((string) ($this->routes()['map_tiles'] ?? ''), $path);
        if ($asked === null) {
            return null;
        }
        [$zoom, $x, $y] = $asked;
        // From the web: the bundled engine locked, it answers what it says is public only; else the engine of the grid, its open operation
        $bundled = self::engineChannel($this->config(), $this->apiKey() !== '') === 'bundled' ? $this->bundled() : null;

        return $bundled !== null
            ? $bundled->publicRoute('GET', '/grids/'.rawurlencode($bundled->nick())."/map/$zoom/$x/$y.jpg")
            : $this->engine()->mapTile($zoom, $x, $y);
    }

    /** The zoom and the region of a tile of the map a path asks under its route, null for another path. @return ?array{int, int, int} */
    public static function tileAsked(string $route, string $path): ?array
    {
        $route = rtrim($route, '/');

        return $route !== '' && preg_match('#^'.preg_quote($route, '#').'/([1-8])/(-?\d+)/(-?\d+)\.jpg$#', $path, $m) === 1 ? [(int) $m[1], (int) $m[2], (int) $m[3]] : null;
    }

    /**
     * The address of a file of the assets of the helpers: theirs, or the one of a library they name by its prefix (View::LIBRARIES:
     * Leaflet), from its package in the vendor of the plugin.
     */
    public static function helpersAsset(string $file): string
    {
        $libraries = defined(\W4OS\Helpers\View\View::class.'::LIBRARIES') ? \W4OS\Helpers\View\View::LIBRARIES : [];
        foreach ($libraries as $prefix => $library) {
            if (str_starts_with($file, $prefix)) {
                return 'plugin://w4os/vendor/'.$library['package'].'/'.$library['folder'].substr($file, strlen($prefix));
            }
        }

        return 'plugin://w4os/vendor/w4os/w4os-helpers/assets/'.$file;
    }

    /** The address the pictures of the grid are under: the web assets the site provides, else where the engine says. */
    public function assetsUrl(): string
    {
        $route = $this->assetsRoute();

        // The path of the settings is from the root of the site, never under the root of Grav again
        return $route !== null ? rtrim($this->grav['uri']->base(), '/').$route : $this->engine()->assetsUrl();
    }

    /** A path of the site as Grav has it under its root (`/opensim/economy/assets` under `/opensim` is `/economy/assets`), null outside it. */
    public static function routeUnder(string $route, string $root): ?string
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            return $route;
        }

        return str_starts_with($route, "$root/") ? substr($route, strlen($root)) : null;
    }

    /** The address of a picture of the grid (a model, a profile), by its asset UUID. */
    public function assetUrl(string $uuid, string $format = 'jpg'): string
    {
        return $this->assetsUrl().'/'.rawurlencode($uuid).'.'.$format;
    }

    /**
     * The id and the format of a picture asked under a route (<route>/<id>.jpg): an asset UUID, or the image of a title the engine
     * gives a place that has none; null for another address.
     */
    public static function assetAsked(string $route, string $path): ?array
    {
        Bundled::library();
        $id = class_exists(\W4OS\Engine\Assets\TitleImage::class) ? \W4OS\Engine\Assets\TitleImage::ID_PATTERN : '[0-9a-fA-F-]{36}';

        return preg_match('#^'.preg_quote(rtrim($route, '/'), '#').'/('.$id.')\.(jpg|png)$#', $path, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    /**
     * Where the site serves the web assets of the grid (the engine's settings `assets`): the route of their path when it provides them,
     * null when another server does or the engine does not say; kept a while, forgotten when they are saved (forgetAssetsRoute()).
     */
    public function assetsRoute(): ?string
    {
        $cache = $this->grav['cache'];
        $route = $cache->fetch(self::ASSETS_ROUTE);
        if (! is_string($route)) {
            try {
                $route = self::providedRoute($this->engine()->settings('assets')) ?? '';
            } catch (\Throwable) {
                $route = '';
            }
            $cache->save(self::ASSETS_ROUTE, $route, 600);
        }

        return $route === '' ? null : $route;
    }

    public function forgetAssetsRoute(): void
    {
        $this->grav['cache']->delete(self::ASSETS_ROUTE);
    }

    /** The route of a service the site provides (its settings: `provide`, `path` under the site), null when it does not. */
    public static function providedRoute(array $settings): ?string
    {
        $path = trim((string) ($settings['path'] ?? ''), '/');

        return ! empty($settings['provide']) && $path !== '' ? "/$path" : null;
    }

    /**
     * Whether the grid takes new avatars from its site: its registration open (the engine's setting `registration` of the avatars,
     * open when it says nothing), kept ten minutes, forgotten once the settings of the avatars are saved; open when the engine does
     * not answer.
     */
    public function registrationOpen(): bool
    {
        $cache = $this->grav['cache'];
        $kept = $cache->fetch(self::REGISTRATION);
        if (! is_string($kept)) {
            try {
                $kept = (string) ($this->engine()->settings('avatars')['registration'] ?? 'open');
                $cache->save(self::REGISTRATION, $kept, 600);
            } catch (\Throwable) {
                return true;
            }
        }

        return $kept !== 'closed';
    }

    /** The registration of the grid read again from the engine (its settings of the avatars changed). */
    public function forgetRegistration(): void
    {
        $this->grav['cache']->delete(self::REGISTRATION);
    }

    /** The key of the cache of the registration of the grid, open or closed. */
    private const REGISTRATION = 'w4os-registration';

    /** The key of the cache of the route of the web assets. */
    private const ASSETS_ROUTE = 'w4os-assets-route';

    public function forms(): Forms
    {
        return new Forms($this->engine(), $this->config(), fn (string $type) => $this->url($type));
    }

    public function blocks(): Blocks
    {
        // The blocks of the helpers the site serves, or of the bundled engine without other helpers; else the ones of the helpers of the grid
        $bundled = $this->bundled();
        if ($bundled && ($this->servesHelpers() || trim((string) ($this->config()['url'] ?? '')) === '')) {
            $rendered = fn (string $name, array $args) => $bundled->rendered($name, $args) ?? throw new BlockUnavailable('the bundled helpers gave nothing', 'admin_blocks_nothing');

            return new Blocks(fn (string $name, array $args) => $this->noticed(Blocks::kept($name)
                ? $this->cached("block $name ".json_encode($args).$this->profileVersion($name, $args), fn () => $rendered($name, $args))
                : $rendered($name, $args)));
        }

        return Blocks::remote((string) ($this->config()['url'] ?? ''), function (string $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
            $name = basename((string) parse_url($url, PHP_URL_PATH));

            return $this->noticed(Blocks::kept($name) ? $this->cached($url.$this->profileVersion($name, $args), fn () => $this->block($url)) : $this->block($url));
        });
    }

    /**
     * Forget what the site keeps of the profile of an avatar (its blocks, in every language and form), once it changed: its page
     * shows the change at once. A change made in the viewer shows once the blocks expire (the setting "cache", in seconds).
     */
    public function forgetProfile(string $name): void
    {
        $name === '' || $this->grav['cache']->save($this->profileKey($name), uniqid('', true), 86400);
    }

    /** What the blocks of a profile are kept under besides their arguments: the version of the avatar's profile (forgetProfile()). */
    private function profileVersion(string $block, array $args): string
    {
        $name = (string) ($args['name'] ?? '');

        return $block !== 'profile' || $name === '' ? '' : ' '.(string) ($this->grav['cache']->fetch($this->profileKey($name)) ?: '');
    }

    /** The key of the version of an avatar's profile, by its name ("First Last", "First.Last", whatever the case). */
    private function profileKey(string $name): string
    {
        return 'w4os-profile-'.md5(strtolower(trim((string) preg_replace('/[\s._]+/', ' ', $name))));
    }

    /**
     * The address of a logo of the engine the plugin bundles (a file of W4OS\Engine\Assets\Logos::FILES), null when it has none.
     */
    public function logo(string $file): ?string
    {
        Bundled::library();
        if (! class_exists(\W4OS\Engine\Assets\Logos::class) || ! in_array($file, \W4OS\Engine\Assets\Logos::FILES, true)) {
            return null;
        }
        $path = 'vendor/w4os/w4os-engine/'.\W4OS\Engine\Assets\Logos::FOLDER.'/'.$file;

        return is_file(dirname(__DIR__).'/'.$path) ? \Grav\Common\Utils::url("plugin://w4os/$path") : null;
    }

    /** The logo of the grid: its own (LogoURL of its helpers), else the OpenSimulator logo. */
    public function gridLogo(): ?string
    {
        return $this->info('logo', fn () => $this->engine()->gridLogo()) ?: $this->logo('opensimulator-logo.png');
    }

    /**
     * The address of a picture of the grid; for none, the engine's picture of nothing: the silhouette of a person (`person`, a
     * profile) or the one of a place. The engine shows it too for a picture it does not have (`?default=`).
     */
    public function picture(?string $uuid, ?string $default = null): string
    {
        Bundled::library();
        $person = $default === 'person';
        $none = $uuid === null || trim($uuid, "0- ") === '';
        $placeholder = class_exists(\W4OS\Engine\Assets\WebAssets::class)
            ? ($person ? \W4OS\Engine\Assets\WebAssets::PERSON : \W4OS\Engine\Assets\WebAssets::PLACEHOLDER)
            : '00000000-0000-0000-0000-000000000000';

        return $this->assetUrl($none ? $placeholder : (string) $uuid).($person ? '?default=person' : '');
    }

    /**
     * A picture of the grid that never shows broken, never under initials: the picture when there is one ($src), its initials
     * (initials()) on a tint when there is none, or when it cannot be loaded (the picture taken away then); $class gives its shape.
     */
    public static function pictureTag(?string $src, string $initials, string $class = '', int $size = 32): string
    {
        $esc = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $picture = $src === null || $src === '' ? '' : '<img src="'.$esc($src).'" alt="" width="'.$size.'" height="'.$size.'" onerror="this.parentNode.classList.remove(\'w4os-pic-shown\');this.remove()">';

        return '<span class="'.$esc(trim('w4os-pic'.($picture === '' ? '' : ' w4os-pic-shown')." $class")).'" style="width: '.$size.'px; height: '.$size.'px" data-initials="'.$esc($initials).'">'.$picture.'</span>';
    }

    /** Whether a picture of the grid is one: an asset UUID that is not the one of nothing (zeros). */
    public static function hasPicture(?string $uuid): bool
    {
        return $uuid !== null && trim($uuid, "0- ") !== '';
    }

    /** The initials of a name (Ada Love: AL; Plaza: P), '' for none. */
    public static function initials(?string $name): string
    {
        $words = preg_split('/[\s._-]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    /**
     * The avatar models, with the address of their picture, kept for the time of the setting "cache"; the ones another rule picks
     * (Engine::models(), the preview of the rule as it is changed) asked each time.
     *
     * @param array<string,string> $rule
     * @return list<array<string,mixed>>
     */
    public function models(array $rule = []): array
    {
        $read = function () use ($rule) {
            try {
                return array_map(fn (array $m) => $m + ['image' => $this->assetUrl((string) $m['picture'])], $this->engine()->models($rule));
            } catch (EngineException $e) {
                $this->grav['log']->warning('w4os: avatar models: '.$e->getMessage());

                return null;
            }
        };
        $models = $rule === [] ? $this->cached('models', $read) : $read();

        return is_array($models) ? $models : [];
    }

    /** The name of the grid, kept for the time of the setting "cache"; empty when the engine cannot tell it. */
    public function gridName(): string
    {
        return $this->info('grid name', fn () => $this->engine()->gridName());
    }

    /**
     * The visit of the grid from another one, for the avatars of other grids: its address to enter in a viewer (its gatekeeper,
     * host:port, its teleport link built from it) and the grids they may come from, as the site names them (setting
     * hypergrid.examples, '' for none); null when the grid is not reachable by hypergrid (no gatekeeper).
     *
     * @return array{address: string, examples: string}|null
     */
    public function hypergrid(): ?array
    {
        $visit = self::hypergridVisit($this->info('gatekeeper', fn () => $this->engine()->gatekeeper()));

        $hypergrid = (array) ($this->config()['hypergrid'] ?? []);
        $examples = self::examplesOfDay((array) ($hypergrid['examples'] ?? []), (int) ($hypergrid['count'] ?? 3), gmdate('Y-m-d'));

        return $visit === null ? null : $visit + ['examples' => self::joinOr($examples, $this->translate('w4os.list_or'))];
    }

    /**
     * A few names of a list, others each day and the same all day: shuffled by a hash of the day and the name (no storage, the
     * same on every request and every server of that day, a page kept in a cache stays right); $count 0 or more than the list:
     * all of them, in the order of the day.
     *
     * @return list<string>
     */
    public static function examplesOfDay(array $names, int $count, string $day): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($name) => trim((string) $name), $names), fn (string $name) => $name !== '')));
        usort($names, fn (string $a, string $b) => strcmp(hash('sha256', "$day|$a"), hash('sha256', "$day|$b")));

        return $count > 0 ? array_slice($names, 0, $count) : $names;
    }

    /** Names joined as a language lists choices (A, B or C): the others by a comma, the last two by its pattern (list_or); '' for none. */
    public static function joinOr(array $names, string $pattern): string
    {
        $names = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $names), fn (string $name) => $name !== ''));
        $last = array_pop($names);

        return $last === null ? '' : ($names === [] ? $last : sprintf($pattern, implode(', ', $names), $last));
    }

    /**
     * The visit of a grid by its gatekeeper (http://host:port, host:port): its address host:port, to enter in a viewer and to go
     * to by its teleport link (tpLink()); null without a gatekeeper.
     *
     * @return array{address: string}|null
     */
    public static function hypergridVisit(string $gatekeeper): ?array
    {
        $address = trim($gatekeeper) === '' ? null : Destination::loginUri(trim($gatekeeper));

        return $address === null || $address === '' ? null : ['address' => $address];
    }

    /** The login URI of the grid (host:port), kept for the time of the setting "cache"; empty when the engine cannot tell it. */
    public function loginUri(): string
    {
        return $this->info('login uri', fn () => $this->engine()->loginUri());
    }

    /**
     * The links that add the grid to a viewer (hop, secondlife), kept for the time of the setting "cache"; null when the engine
     * cannot tell them.
     *
     * @return array{hop: string, secondlife: string}|null
     */
    public function addGrid(): ?array
    {
        $links = $this->cached('add grid', function () {
            try {
                return $this->engine()->addGrid();
            } catch (EngineException $e) {
                return null;
            }
        });

        return is_array($links) ? $links : null;
    }

    /** The link that opens the viewer on its login with the grid of this login URI (host:port) chosen, null without one. */
    public static function loginLink(string $loginUri): ?string
    {
        return $loginUri === '' ? null : 'secondlife:///app/login?grid='.rawurlencode($loginUri);
    }

    /**
     * The steps of a newcomer and their state: done, next (the one to do now) or todo. The avatar is done once signed in; adding the
     * grid to the viewer and logging in are done once the avatar has logged in-world (a viewer that logs in has the grid). Registration
     * closed ($open false), a visitor has no avatar to create: no such step (the managers create the avatars).
     *
     * @return list<array{step: string, state: string}>
     */
    public static function steps(bool $signedIn, ?bool $visited, bool $open = true): array
    {
        $done = ['avatar' => $signedIn, 'viewer' => $signedIn && $visited === true, 'login' => $signedIn && $visited === true];
        ! $open && ! $signedIn && $done = array_diff_key($done, ['avatar' => true]);
        $steps = [];
        $next = false;
        foreach ($done as $step => $isDone) {
            $state = $isDone ? 'done' : ($next ? 'todo' : 'next');
            $isDone || $next = true;
            $steps[] = ['step' => $step, 'state' => $state];
        }

        return $steps;
    }

    private function info(string $key, \Closure $value): string
    {
        $kept = $this->cached($key, function () use ($value) {
            try {
                return $value() ?: null;
            } catch (EngineException $e) {
                return null;
            }
        });

        return is_string($kept) ? $kept : '';
    }

    /** @return array<string,string> the route of each page of the plugin, by its type */
    public function routes(): array
    {
        $routes = array_map(fn ($route) => '/'.trim((string) $route, '/'), array_filter((array) ($this->config()['routes'] ?? [])));
        $config = $this->grav['config'];
        $reserved = array_filter([(string) $config->get('plugins.admin.route', ''), (string) $config->get('plugins.admin2.route', '')]);
        $set = '/'.trim((string) ($routes['admin'] ?? self::ADMIN_ROUTE), '/');
        $routes = self::adminRoutes($routes, $reserved);
        $set === $routes['dashboard'] || isset($this->config()['routes']['dashboard'])
            || $this->grav['log']->warning("w4os: the admin route $set is under the route of Grav's admin: {$routes['dashboard']} instead");
        // Registration closed (the engine's setting of the grid): no page of it, nor a link to it; its address says it is closed (a
        // viewer's "create an account" lands there)
        if (! $this->registrationOpen()) {
            isset($routes['register']) && $routes['register_closed'] = $routes['register'];
            unset($routes['register'], $routes['confirm']);
        }

        return $routes;
    }

    /**
     * The pages of the grid the plugin serves, by the engine's name of each (its site pages): the route of the plugin that serves it,
     * the label of its setting. The registration only while it is open (routes()).
     */
    public const SITE_PAGES = [
        'profile' => ['route' => 'profile', 'label' => 'setting_web_profile'],
        'search' => ['route' => 'search', 'label' => 'setting_web_search'],
        'guide' => ['route' => 'guide', 'label' => 'setting_destination_guide'],
        'welcome' => ['route' => 'welcome', 'label' => 'setting_welcome'],
        'register' => ['route' => 'register', 'label' => 'setting_register'],
        'password' => ['route' => 'password', 'label' => 'setting_password'],
    ];

    /** The addresses of its pages the site declared to the engine last, by page. */
    public const SITE_PAGES_FILE = 'user-data://w4os/site-pages.json';

    /**
     * The address of each page of the grid the site serves, by page: its route at the base of the site (no language: the grid gives
     * it to every viewer); none for a page it has no route for.
     *
     * @param array<string,string> $routes the routes of the plugin
     * @return array<string,string>
     */
    public static function servedPages(array $routes, string $base): array
    {
        $pages = [];
        foreach (self::SITE_PAGES as $page => $definition) {
            $route = (string) ($routes[$definition['route']] ?? '');
            $route === '' || $route === '/' || $pages[$page] = rtrim($base, '/').$route;
        }

        return $pages;
    }

    /**
     * The pages to declare: all of them ($all), else the ones the site never declared, or at another path (its prefix, its route
     * changed); never for another host alone (the same site reached at another name).
     *
     * @param array<string,string> $served the addresses of the pages now (servedPages())
     * @param array<string,string> $declared the ones declared last
     * @return array<string,string>
     */
    public static function pagesToDeclare(array $served, array $declared, bool $all = false): array
    {
        $path = fn (?string $url) => $url === null ? null : (string) parse_url($url, PHP_URL_PATH);

        return $all ? $served : array_filter($served, fn (string $url, string $page) => $path($url) !== $path($declared[$page] ?? null), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The pages of the grid the site serves that the engine was never told, or at another path (the prefix of the site, a route),
     * by page: their address now, and whether they moved; nothing is written (the dashboard offers to announce them).
     *
     * @return array<string,array{url: string, moved: bool}>
     */
    public function pendingPages(): array
    {
        $declared = $this->declaredPages();
        $pending = [];
        foreach (self::pagesToDeclare(self::servedPages($this->routes(), (string) $this->grav['uri']->rootUrl(true)), $declared) as $page => $url) {
            $pending[$page] = ['url' => $url, 'moved' => isset($declared[$page])];
        }

        return $pending;
    }

    /** @return array<string,string> the addresses of its pages the site declared last, by page */
    private function declaredPages(): array
    {
        $file = (string) $this->grav['locator']->findResource(self::SITE_PAGES_FILE);
        $declared = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($declared) ? $declared : [];
    }

    /**
     * Declare to the engine the pages of the grid the site serves (its site pages), their addresses kept as declared: all of them
     * ($all: the settings of the site saved), else the ones that changed (pendingPages(): the manager announces them). A page the
     * grid has from elsewhere (the grid it was migrated from, a manager) stays the grid's: said, never an error; an engine that
     * cannot be reached is asked again next time.
     *
     * @return list<string|array<string,mixed>> what to tell the manager: what the engine leaves to do (a restart), the pages it kept
     */
    public function declarePages(bool $all = false): array
    {
        $file = (string) $this->grav['locator']->findResource('user-data://w4os', true, true).'/'.basename(self::SITE_PAGES_FILE);
        $before = $this->declaredPages();
        $done = self::declareTo(
            $this->engine(),
            self::pagesToDeclare(self::servedPages($this->routes(), (string) $this->grav['uri']->rootUrl(true)), $before, $all),
            $all,
            fn (string $page) => $this->translate('w4os.admin_site_page_kept', [$this->translate('w4os.'.self::SITE_PAGES[$page]['label'])]),
            fn (string $page, string $why) => $this->grav['log']->warning("w4os: the page $page not declared to the engine: $why"),
        );
        $declared = $done['declared'] + $before;
        $declared === $before || file_put_contents($file, json_encode($declared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $done['notes'];
    }

    /**
     * Declare pages to the engine, each at its address: checked first ($check: only a page the grid does not have as the site
     * declared it is written, a write has Robust restart); a page the grid has from elsewhere (`page-set-elsewhere`) said by $kept.
     *
     * @param array<string,string> $pages the address of each page
     * @param \Closure(string $page): string $kept the text of a page the grid keeps
     * @param \Closure(string $page, string $why): void $failed a page the engine could not take
     * @return array{declared: array<string,string>, notes: list<string|array<string,mixed>>} the pages the engine settled (not when it
     *         cannot be reached, or does not know the page yet: asked again), what to tell the manager
     */
    public static function declareTo(Engine $engine, array $pages, bool $check, \Closure $kept, \Closure $failed): array
    {
        $declared = [];
        $notes = [];
        foreach ($pages as $page => $url) {
            try {
                $now = $check ? $engine->sitePage($page) : [];
                if (! (($now['declared'] ?? false) && self::samePage((string) ($now['url'] ?? ''), $url))) {
                    array_push($notes, ...array_values((array) ($engine->declareSitePage($page, $url)['meta']['reminders'] ?? [])));
                }
            } catch (EngineException $e) {
                $e->problemCode === 'page-set-elsewhere' ? $notes[] = $kept($page) : $failed($page, $e->getMessage());
                // Asked again unless the engine said it for good: the grid keeps its own, or it has no files here
                if (! in_array($e->problemCode, ['page-set-elsewhere', 'channel-unavailable'], true) || $e->problem === []) {
                    continue;
                }
            }
            $declared[$page] = $url;
        }

        return ['declared' => $declared, 'notes' => array_values(array_unique($notes, SORT_REGULAR))];
    }

    /** Whether two addresses are the same page, the name of an avatar a profile ends with left out. */
    public static function samePage(string $one, string $other): bool
    {
        $bare = fn (string $url) => rtrim(str_replace('[AGENT_NAME]', '', $url), '/');

        return $bare($one) === $bare($other);
    }

    /** The admin route of the plugin by default, and its sections in it. */
    public const ADMIN_ROUTE = '/w4os';

    public const ADMIN_ROUTES = [
        'dashboard' => '', 'manage' => '/avatars', 'edit' => '/avatars/edit', 'new' => '/avatars/new', 'regions' => '/regions',
        'region' => '/regions/region', 'simulators' => '/simulators', 'simulator' => '/simulators/simulator', 'models' => '/models', 'health' => '/health',
        'shortcodes' => '/shortcodes', 'settings' => '/settings', 'flux' => '/flux',
        'settings_economy' => '/economy', 'settings_search' => '/search', 'settings_guide' => '/guide', 'settings_offline' => '/offline',
        'settings_assets' => '/assets', 'settings_grid' => '/grid', 'settings_avatars' => '/avatars/settings', 'settings_map' => '/regions/map',
    ];

    /** Where the admin pages were before they had a route of their own: the sites that kept them there get the admin route. */
    public const OLD_ADMIN_ROUTES = [
        'dashboard' => '/account/dashboard', 'manage' => '/account/avatars', 'edit' => '/account/avatars/edit', 'regions' => '/account/regions',
        'region' => '/account/regions/region', 'simulators' => '/account/simulators', 'settings' => '/account/settings',
    ];

    /**
     * The routes with the admin pages under the admin route of the plugin (routes.admin, /w4os), each section in it unless the
     * site names one itself; the default route when the one set is under a route of Grav's admin (its plugins admin, admin2 take
     * all of theirs).
     *
     * @param array<string,string> $routes the routes of the plugin
     * @param list<string> $reserved the routes of Grav's admin plugins
     * @return array<string,string>
     */
    public static function adminRoutes(array $routes, array $reserved): array
    {
        $admin = '/'.trim((string) ($routes['admin'] ?? self::ADMIN_ROUTE), '/');
        foreach ($reserved as $route) {
            $route = '/'.trim($route, '/');
            if ($admin === $route || str_starts_with($admin, rtrim($route, '/').'/')) {
                $admin = self::ADMIN_ROUTE;
            }
        }
        // The base of the sections, no page of its own (the dashboard is there)
        unset($routes['admin']);
        foreach (self::ADMIN_ROUTES as $type => $section) {
            if (! isset($routes[$type]) || $routes[$type] === (self::OLD_ADMIN_ROUTES[$type] ?? null)) {
                $routes[$type] = $admin === '/' ? ($section ?: '/') : $admin.$section;
            }
        }

        return $routes;
    }

    /** The new address of an admin page reached at its old one (under /account), null for any other. */
    public static function movedRoute(string $path, array $routes): ?string
    {
        $type = array_search('/'.trim($path, '/'), self::OLD_ADMIN_ROUTES, true);

        return $type !== false && ($routes[$type] ?? null) !== self::OLD_ADMIN_ROUTES[$type] ? $routes[$type] : null;
    }

    /**
     * The links of the site for the page shown (the engine's Url): its base, with its host when absolute, the prefix of the language
     * of the visitor (none without $language: the pictures), the languages of the site, so a route that has its prefix already keeps it.
     */
    public function links(bool $absolute = false, bool $language = true): Url
    {
        $languages = $this->grav['language'];

        return self::siteLinks((string) $this->grav['uri']->rootUrl($absolute), $language ? (string) $languages->getLanguageURLPrefix() : '', (array) $languages->getLanguages(), (string) $this->grav['uri']->path());
    }

    /** The links of a site at this base, in the language of this prefix ('/fr', '' for none), on the page of this path. */
    public static function siteLinks(string $base, string $prefix, array $languages, string $path): Url
    {
        return new Url(base: $base, language: trim($prefix, '/'), languages: array_values(array_map('strval', $languages)), path: $path);
    }

    /**
     * A text a site gives a block (Markdown, inline HTML), as the text of a page: Grav's Markdown, its links to a path of the site in
     * the language of the visitor (localLinks()); a link alone a link to more with $more (moreLink()); '' for none.
     */
    public function text(?string $text, bool $more = false): string
    {
        $text = trim((string) $text);
        $html = $text === '' ? '' : self::localLinks(\Grav\Common\Utils::processMarkdown($text), $this->links());

        return $more ? self::moreLink($html) : $html;
    }

    /** A text that is one link alone, a link to more (.w4os-more: at the right, a chevron before it); any other as it is. */
    public static function moreLink(string $html): string
    {
        if (preg_match('#^\s*<p>\s*<a\s[^>]*>(?:(?!</a>).)*</a>\s*</p>\s*$#s', $html) !== 1 || str_contains($html, 'w4os-more')) {
            return $html;
        }

        return (string) preg_replace('#<a\s(?![^>]*\sclass=)#', '<a class="w4os-more" ', $html, 1);
    }

    /** The links of some HTML to a path of the site (href="/help") built for the page shown: its base and language, never twice. */
    public static function localLinks(string $html, Url $links): string
    {
        return (string) preg_replace_callback('#\shref="(/(?!/)[^"]*)"#', fn (array $m) => ' href="'.htmlspecialchars($links->build(html_entity_decode($m[1], ENT_QUOTES)), ENT_QUOTES).'"', $html);
    }

    /** The path of a route of the plugin on this site, without the host: a page shows it whatever address it was reached at. */
    public function path(string $type, bool $language = false): string
    {
        return $this->links(false, $language)->build($this->routes()[$type] ?? '/');
    }

    /** The absolute address of a page of the plugin, in the language of the visitor (or none: the pictures). */
    public function url(string $type, bool $language = true): string
    {
        return $this->links(true, $language)->build($this->routes()[$type] ?? '/');
    }

    /**
     * The page of the plugin at the route asked for, unless the site has its own page there. The front matter is the one of
     * pages/<type>.md (its forms), title and text come from the language files.
     */
    /** The pages whose route is a prefix: the rest of the path is theirs (a topic of the search, the name of a profile). */
    public const PREFIX_PAGES = ['search', 'profile'];

    /** The type of the page of the plugin at a path, and the rest of the path after its route (a topic, a name). */
    public function pageAt(string $path): array
    {
        return self::typeAt($path, $this->routes());
    }

    /** The blocks of the helpers the page a viewer shows before the login has (templates/w4os/welcome.html.twig), by their names. */
    public const WELCOME_BLOCKS = ['grid_info', 'grid_status', 'popular_places', 'motd'];

    /** The routes that show the page of another type: password (the address grids name for a lost password) shows forgot. */
    public const SAME_PAGES = ['password' => 'forgot'];

    /**
     * The type of the page of the plugin at a path, by the routes of the site, with what follows a route that takes a name (a
     * profile), [false, ''] for none.
     *
     * @param array<string,string> $routes
     * @return array{0: string|false, 1: string}
     */
    public static function typeAt(string $path, array $routes): array
    {
        $type = array_search($path, $routes, true);
        if ($type !== false) {
            return [self::SAME_PAGES[$type] ?? $type, ''];
        }
        foreach (self::PREFIX_PAGES as $prefix) {
            $route = $routes[$prefix] ?? '';
            if ($route !== '' && $route !== '/' && str_starts_with($path, "$route/")) {
                return [$prefix, rawurldecode(substr($path, strlen($route) + 1))];
            }
        }

        return [false, ''];
    }

    /**
     * Whether a page of the site is shown in a viewer without what the theme draws around its content (w4os-viewer-frame.html.twig):
     * an HTML page in a viewer, whose template no one set already (the plugin's own pages, another plugin's), never an admin page.
     *
     * @param ?string $set the template set for the page already (Twig's `template`)
     * @param ?string $template the template of the page
     */
    public static function viewerFrame(bool $viewer, ?string $set, ?string $template, string $format): bool
    {
        return $viewer && ($set ?? '') === '' && ($template ?? '') !== '' && $template !== 'w4os-admin' && $format === 'html';
    }

    /**
     * The layer of the styles of a client (client()): `web` for a browser, `viewer` for a viewer. A page has the common styles, then
     * the ones of its client alone, never both (css/w4os.<layer>.css, and the helpers' View::blockFiles(), View::pageStyles()).
     */
    public static function layer(string $client): string
    {
        return $client === 'viewer' ? 'viewer' : 'web';
    }

    /** Whether a viewer asks the page (its user agent, as the engine tells it): the page then shows its content alone. */
    public function isViewer(): bool
    {
        return $this->client() === 'viewer';
    }

    /**
     * A teleport link that fits where it is seen (W4OS\Views\TeleportLink): the links the engine gives a destination for a browser
     * and for the viewer (kept for the time of the setting "cache"), their labels in the language of the page, the main one of the
     * client the server tells; its text the one given, else the name of the place (its region; a grid alone, its address). The
     * address alone, as text, when the engine gives no link.
     */
    public function tpLink(string $destination, ?string $label = null, ?string $html = null, ?string $prefer = null, ?array $only = null): string
    {
        $destination = trim($destination);
        $place = $destination === '' ? [] : $this->cached('place '.$destination, function () use ($destination) {
            try {
                return $this->engine()->place($destination);
            } catch (EngineException $e) {
                return [];
            }
        });
        $place = is_array($place) ? $place : [];
        $links = [];
        foreach (\W4OS\Views\TeleportLink::CLIENTS as $client) {
            $links[$client] = array_map(fn ($link) => ['label' => $this->translate('w4os.'.($link['label'] ?? ''))] + (array) $link, (array) ($place[$client] ?? []));
        }
        $text = trim((string) $label) !== '' ? (string) $label : self::placeName((string) ($place['tpUri'] ?? $destination));

        return \W4OS\Views\TeleportLink::html($links, $text, $this->client(), [
            'more' => $this->translate('w4os.tp_more'),
            'copied' => $this->translate('w4os.copied'),
        ], self::viewerPattern(), $html, $prefer, $only === null ? null : array_values(array_map('strval', $only)));
    }

    /** The name a place is shown by: its region (host:port/Region/x/y/z, host:port:Region), a grid alone its address. */
    public static function placeName(string $uri): string
    {
        $uri = preg_replace('#^[a-z]+://#i', '', trim($uri)) ?? '';
        if (preg_match('#^[^/:]+:\d+[/:]([^/]+)#', $uri, $m) === 1) {
            return rawurldecode($m[1]);
        }

        return rtrim(explode('/', $uri)[0], ':');
    }

    /** The user agents of the viewers, as the engine knows them (Destination::VIEWER_PATTERN), for the script of a page. */
    public static function viewerPattern(): string
    {
        Bundled::library();
        $pattern = defined(\W4OS\Engine\Hypergrid\Destination::class.'::VIEWER_PATTERN') ? (string) constant(\W4OS\Engine\Hypergrid\Destination::class.'::VIEWER_PATTERN') : '/SecondLife|OpenSim|Firestorm/i';

        return trim((string) preg_replace('#^/(.*)/[a-z]*$#s', '$1', $pattern));
    }

    /** The class of the visitor, as the engine tells it from its user agent (Destination::client()): `viewer` or `browser`. */
    public function client(): string
    {
        Bundled::library();

        return class_exists(\W4OS\Engine\Hypergrid\Destination::class) && method_exists(\W4OS\Engine\Hypergrid\Destination::class, 'client')
            ? \W4OS\Engine\Hypergrid\Destination::client((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
            : 'browser';
    }

    /** The sign-in page with links under its form, the Login plugin's or a theme's own: the form of the task `login.login`. */
    public static function withLoginLinks(string $html, string $links): string
    {
        $task = strpos($html, 'value="login.login"');
        $end = $task === false ? false : strpos($html, '</form>', $task);

        return $end === false ? $html : substr($html, 0, $end + 7).$links.substr($html, $end + 7);
    }

    /**
     * The menu of the site, one menu of the pages (as every theme draws them): the search; my avatar, to the profile, with under it
     * once signed in the edit of the profile and sign out; the admin for a manager, to its dashboard, its sections under it.
     *
     * @param array<string,string> $routes the routes of the plugin
     * @param list<array{type: string, route: string, label: string}> $admin the sections of the admin the visitor opens
     * @return list<array{type: string, route: string, label: string, children: list<array<string,mixed>>}>
     */
    public static function menuEntries(array $routes, bool $signedIn, array $admin): array
    {
        $entries = [];
        isset($routes['search']) && $entries[] = ['type' => 'search', 'route' => $routes['search'], 'label' => 'w4os.search', 'children' => []];
        $mine = $signedIn && isset($routes['account']) ? [
            ['type' => 'account', 'route' => $routes['account'], 'label' => 'w4os.edit_profile'],
            ['type' => 'logout', 'route' => self::logoutRoute($routes), 'label' => 'w4os.logout'],
        ] : [];
        // A visitor: signing in (the page of the Login plugin) and creating an avatar
        if (! $signedIn) {
            isset($routes['login']) && $mine[] = ['type' => 'login', 'route' => $routes['login'], 'label' => 'w4os.login_title'];
            isset($routes['register']) && $mine[] = ['type' => 'register', 'route' => $routes['register'], 'label' => 'w4os.register_title'];
        }
        isset($routes['profile']) && $entries[] = ['type' => 'profile', 'route' => $routes['profile'], 'label' => 'w4os.menu_account', 'children' => $mine];
        $admin === [] || ! isset($routes['dashboard']) || $entries[] = ['type' => 'admin', 'route' => $routes['dashboard'], 'label' => 'w4os.menu_admin', 'children' => $admin];

        return $entries;
    }

    /** The route that signs the visitor out (the task of the Login plugin), under the account. */
    public static function logoutRoute(array $routes): string
    {
        return rtrim((string) ($routes['account'] ?? '/account'), '/').'/logout';
    }

    /**
     * The sections of the admin the visitor opens, for the menu of the site: its pages but the dashboard (the admin's own link).
     *
     * @return list<array{type: string, route: string, label: string}>
     */
    public function adminSections(): array
    {
        $sections = [];
        foreach (self::ADMIN_PAGES as $type => $page) {
            $type !== 'dashboard' && isset($this->routes()[$type]) && (! isset(self::ADMIN_TYPES[$type]) || $this->can(self::ADMIN_TYPES[$type], 'read'))
                && (! isset(self::ADMIN_OPERATIONS[$type]) || $this->canOperation($type))
                && ($type !== 'settings' || $this->canSettings())
                && $sections[] = ['type' => $type, 'route' => $this->routes()[$type], 'label' => $page['label']];
        }

        return $sections;
    }

    /**
     * The menu of the site (setting `menu`, menuEntries()) as pages of the tree of Grav, under its root, their children under them;
     * none under a theme that shows the menus of the visitor itself (menuShown()).
     */
    public function addMenu(): void
    {
        if (! self::menuShown($this->config(), (array) $this->grav['config']->get('theme', []))) {
            return;
        }
        $root = $this->grav['pages']->root();
        $routes = $this->routes() + ['login' => '/'.trim((string) ($this->grav['config']->get('plugins.login.route') ?: '/login'), '/')];
        foreach (self::menuEntries($routes, $this->avatar() !== null, $this->isManager() ? $this->adminSections() : []) as $entry) {
            $this->menuPage($entry, $root);
        }
    }

    /**
     * Whether the plugin adds its entries to the menu of the site: as its setting `menu` says, never under a theme that shows the
     * menus of the visitor itself, in its top bar (its setting `w4os_menus: true`, w4os/user-menu.html.twig and w4os/admin-menu.html.twig).
     */
    public static function menuShown(array $config, array $theme): bool
    {
        return (bool) ($config['menu'] ?? true) && ! ($theme['w4os_menus'] ?? false);
    }

    /**
     * The title of the page of an entry of the menu: its label, for the themes that list the pages by their title (Apple Notes);
     * the admin keeps the title of its dashboard, a section of the admin the title of its page.
     */
    public static function menuTitleKey(array $entry): string
    {
        return match (true) {
            $entry['type'] === 'admin' => self::titleKey('dashboard'),
            isset(self::ADMIN_PAGES[$entry['type']]) => self::titleKey((string) $entry['type']),
            default => (string) $entry['label'],
        };
    }

    /** An entry of the menu as a page of the tree, visible, under its parent; a page of the site at its route wins. */
    private function menuPage(array $entry, $parent): void
    {
        $pages = $this->grav['pages'];
        if ($pages->find($entry['route'])) {
            return;
        }
        // The sign-in: the page of the Login plugin, as it makes it (it puts its own there when the page is asked)
        $page = $entry['type'] === 'login'
            ? (isset($this->grav['login']) ? $this->grav['login']->getPage('login', $entry['route']) : null)
            : $this->pluginPage($entry['type'] === 'admin' ? 'dashboard' : $entry['type'], $entry['route']);
        if ($page === null) {
            return;
        }
        $page->menu($this->translate($entry['label']));
        $page->title($this->translate(self::menuTitleKey($entry)));
        // Visible for the themes that read it from its front matter too (Apple Notes)
        $page->visible(true);
        $page->modifyHeader('visible', true);
        $page->parent($parent);
        $pages->addPage($page, $entry['route']);
        foreach ($entry['children'] ?? [] as $child) {
            $this->menuPage($child, $page);
        }
    }

    /** Whether a page asks the splash template of an earlier version of the plugin (`w4os/splash`): the home page, made content. */
    public static function isSplash(?string $template): bool
    {
        return $template === 'w4os/splash';
    }

    /**
     * The blocks of the helpers the text of a page shows through its shortcodes ([grid-status], [w4os-home]), by their names in the
     * helpers (grid_status), for their files before the theme writes its head.
     *
     * @return list<string>
     */
    public static function shortcodeBlocks(string $raw): array
    {
        $blocks = str_contains($raw, '[w4os-home') ? ['popular_places', 'destination_guide', 'grid_info', 'grid_status', 'motd'] : [];

        return array_values(array_unique([...$blocks, ...self::blocksIn($raw)]));
    }

    /** The key of the title of a page of the plugin, in the catalog or the texts of the plugin. */
    public static function titleKey(string $type): string
    {
        return match ($type) {
            'account' => 'w4os.edit_profile',
            'search' => 'w4os.search',
            'settings' => 'w4os.admin_site_settings',
            'health' => 'w4os.admin_health_title',
            'models' => 'w4os.settings_models',
            'new' => 'w4os.action_new_avatar',
            'shortcodes' => 'w4os.shortcodes_title',
            'register_closed' => 'w4os.site_register_closed_title',
            default => self::serviceGroup($type) !== null ? 'w4os.'.$type : 'w4os.'.$type.'_title',
        };
    }

    /** A page of the plugin (pages/<type>.md) at a route of the site, under a folder of its own so that each one is its own page. */
    private function pluginPage(string $type, string $route): Page
    {
        $page = new Page();
        $page->init(new \SplFileInfo(dirname(__DIR__).'/pages/'.$type.'.md'));
        $page->path(dirname(__DIR__).'/pages/'.$type);
        $page->route($route);
        $page->slug(basename($route));
        // The account page is where the avatar edits its profile: the title of its menu entry and button
        $page->title($this->translate(self::titleKey($type)));

        return $page;
    }

    /**
     * The sign-in form with "remember me" checked, as on a social network: the Login plugin has no setting for it; its box is
     * `rememberme` in its form and in the ones of the themes.
     */
    public static function rememberChecked(string $html): string
    {
        return (string) preg_replace_callback('/<input\b[^>]*\bname="rememberme"[^>]*>/', fn ($m) => str_contains($m[0], ' checked') ? $m[0] : substr($m[0], 0, -1).' checked>', $html);
    }

    /**
     * The front matter of a page of the plugin (`manage`, `edit`...): the site's own page at its route when it has one, else the
     * plugin's (pages/<type>.md).
     *
     * @return array<string,mixed>
     */
    public function pageHeader(string $type): array
    {
        $route = $this->routes()[$type] ?? null;
        $own = $route === null ? null : $this->grav['pages']->find($route);
        if ($own !== null) {
            return (array) json_decode((string) json_encode($own->header()), true);
        }
        $file = dirname(__DIR__)."/pages/$type.md";
        if (! is_file($file) || ! preg_match('/^---\R(.*?)\R---/s', (string) file_get_contents($file), $matter)) {
            return [];
        }

        return (array) \Symfony\Component\Yaml\Yaml::parse($matter[1]);
    }

    /**
     * The map's page of the site's tree (its entry in the menu: the plugin's page map) out of the menu and of its address (a 404) while
     * the grid has no map, back once it has one.
     */
    public function hideMapPage(): void
    {
        $route = $this->routes()['map'] ?? null;
        if ($route === null || $this->hasMap()) {
            return;
        }
        $page = $this->grav['pages']->find($route);
        if ($page !== null && (string) ($page->header()->w4os ?? '') === 'map') {
            $page->visible(false);
            $page->routable(false);
        }
    }

    public function addPage(string $path): void
    {
        [$type] = $this->pageAt($path);
        $pages = $this->grav['pages'];
        $found = $pages->find($path);
        // A page of the site at that route wins; the plugin's own already there (the entry of the menu) is the one shown
        if ($type === false || ! is_file(dirname(__DIR__)."/pages/$type.md") || ($found && ! isset($found->header()->w4os))) {
            return;
        }
        // The map of a grid that has none: no page of its own (a 404), never a grid said not to answer
        if ($type === 'map' && ! $this->hasMap()) {
            return;
        }
        $page = $found ?? $this->pluginPage($type, $path);
        $found || $pages->addPage($page, $path);
        unset($this->grav['page']);
        $this->grav['page'] = $page;
    }

    /**
     * The levels the admin pages may open from (setting "admin_level"): the engine's user levels (OpenSimulator's: each has every
     * right of the ones below it), each named by its role.
     *
     * @return array<int,string> level => catalog key of its role
     */
    public static function adminLevels(): array
    {
        if (! Bundled::library()) {
            return [];
        }
        $levels = [];
        foreach (array_keys(\W4OS\Engine\Models\Avatar::LEVELS) as $level) {
            $levels[$level] = 'w4os.'.\W4OS\Engine\I18n\Catalog::roleKey(\W4OS\Engine\Models\Avatar::roleOf($level));
        }

        return $levels;
    }

    /** The level the admin pages open from: the setting when it is one of adminLevels(), else customer service. */
    public static function adminLevel(array $config): int
    {
        $level = is_numeric($config['admin_level'] ?? null) ? (int) $config['admin_level'] : -1;

        return isset(self::adminLevels()[$level]) ? $level : \W4OS\Engine\Models\Avatar::GOD_CUSTOMER_SERVICE;
    }

    /**
     * The previous addresses of the grid (the engine's /addresses, `previous`: service, old, new, date, source; gridAddresses()).
     *
     * @return list<array<string,mixed>>
     */
    public function previousAddresses(): array
    {
        return array_values(array_filter((array) ($this->gridAddresses()['previous'] ?? []), 'is_array'));
    }

    /** The welcome address of the grid, the one of its splash page (the grid info's `welcome`), '' for none (gridAddresses()). */
    public function welcomeAddress(): string
    {
        return (string) ($this->gridAddresses()['addresses']['welcome'] ?? '');
    }

    /**
     * The addresses of the grid as the engine tells them (its /addresses: each page, the previous ones), kept an hour; none when
     * the engine does not answer, without asking it again before then.
     *
     * @return array<string,mixed>
     */
    public function gridAddresses(): array
    {
        $addresses = $this->cached('grid addresses', function () {
            try {
                return $this->engine()->addresses();
            } catch (\Throwable) {
                return [];
            }
        }, 3600);

        return is_array($addresses) ? $addresses : [];
    }

    /**
     * The redirects of the site (Grav's `site.redirects`) with the previous addresses of the grid on its own host: each old address
     * exactly (its path and its query, a last slash or not), its placeholders ([AGENT_NAME]) carried to the new one, for good
     * (301); a path when the new one is on the same host. The site's own list first: Grav follows the first that matches, so it
     * wins on the same address. An old address on another host (or another port: a grid) is the web server's.
     *
     * @param list<mixed> $previous the pairs (previousAddresses())
     * @param array<string,string> $manual the site's own redirects
     * @return array<string,string>
     */
    public static function redirectsFrom(array $previous, string $host, array $manual): array
    {
        $host = strtolower(trim($host));
        $ours = [];
        foreach ($previous as $pair) {
            $old = is_array($pair) ? parse_url((string) ($pair['old'] ?? '')) : false;
            $new = is_array($pair) ? trim((string) ($pair['new'] ?? '')) : '';
            if (! is_array($old) || strtolower((string) ($old['host'] ?? '')) !== $host || $host === '' || $new === ''
                || ! in_array((int) ($old['port'] ?? 0), [0, 80, 443], true)) {
                continue;
            }
            $query = isset($old['query']) ? '?'.$old['query'] : '';
            $path = (string) ($old['path'] ?? '/');
            // Its placeholders ([AGENT_NAME]) as captures, the rest as it is
            $parts = preg_split('/\[([A-Z_]+)\]/', $path.$query, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $pattern = '';
            $names = [];
            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) {
                    $pattern .= preg_quote($part, '#');
                } else {
                    $names[] = $part;
                    $pattern .= '([^&]*)';
                }
            }
            $query === '' && ($pattern = rtrim($pattern, '/').'/?');
            $target = parse_url($new);
            $local = is_array($target) && strtolower((string) ($target['host'] ?? '')) === $host && ! isset($target['port']);
            $to = $local ? ($target['path'] ?? '/').(isset($target['query']) ? '?'.$target['query'] : '') : $new;
            foreach ($names as $n => $name) {
                $to = str_replace("[$name]", '${'.($n + 1).'}', $to);
            }
            if (rtrim((string) $pair['old'], '/') !== rtrim($new, '/')) {
                $ours[$pattern.'$'] = $to.'[301]';
            }
        }

        return $manual + array_diff_key($ours, $manual);
    }

    /**
     * The access of an avatar as the engine gives it: the level its access is decided by (`accessLevel`: its user level, a god's for
     * the owner of the grid whatever its own; its user level with an engine before it), its user level, whether it owns the grid.
     *
     * @param array<string,mixed> $avatar
     * @return array{level: int, userLevel: int, owner: bool}
     */
    public static function accessOf(array $avatar): array
    {
        $userLevel = (int) ($avatar['level'] ?? 0);

        return ['level' => (int) ($avatar['accessLevel'] ?? $userLevel), 'userLevel' => $userLevel, 'owner' => (bool) ($avatar['owner'] ?? false)];
    }

    /** The pictures of the accounts of the site, the ones of their avatars: a file each, by the account and the picture. */
    public const AVATAR_FOLDER = 'user/accounts/avatars';

    /** The file of the picture of an account: the account and its picture (a new picture, a new file, the old one left behind). */
    public static function avatarFile(string $account, string $picture): string
    {
        return self::AVATAR_FOLDER.'/'.$account.'-'.substr((string) preg_replace('/[^0-9a-f]/', '', strtolower($picture)), 0, 8).'.jpg';
    }

    /**
     * Grav's avatar field of an account with a file (a file field, as its admins read it: getAvatarImage(), the API's avatar_url).
     *
     * @return array<string, array{name: string, type: string, size: int, path: string}>
     */
    public static function avatarField(string $path, int $size): array
    {
        return [$path => ['name' => basename($path), 'type' => 'image/jpeg', 'size' => $size, 'path' => $path]];
    }

    /**
     * The picture of an account, the one of its avatar: kept in the files of the accounts (asked of the engine once a picture, as
     * the pictures of the grid are), its avatar field to it; none without a picture (the initials of its name then), the file of
     * the one before removed. An engine that does not answer leaves the account as it was.
     */
    private function accountPicture(UserInterface $user, string $account, ?string $picture): void
    {
        $account = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $account) ?? '');
        $folder = GRAV_ROOT.'/'.self::AVATAR_FOLDER;
        $file = $account !== '' && self::hasPicture($picture) ? self::avatarFile($account, (string) $picture) : null;
        try {
            if ($file !== null && ! is_file(GRAV_ROOT."/$file")) {
                [$status, $body] = $this->engine()->picture((string) $picture, 'jpg');
                if ($status !== 200 || $body === '') {
                    return;
                }
                is_dir($folder) || mkdir($folder, 0775, true);
                file_put_contents(GRAV_ROOT."/$file", $body);
            }
        } catch (\Throwable $e) {
            $this->grav['log']->warning('w4os: the picture of the account '.$account.' not kept: '.$e->getMessage());

            return;
        }
        foreach ($account === '' ? [] : (glob("$folder/$account-*.jpg") ?: []) as $old) {
            $file !== null && $old === GRAV_ROOT."/$file" || unlink($old);
        }
        $file === null ? $user->undef('avatar') : $user->set('avatar', self::avatarField($file, (int) filesize(GRAV_ROOT."/$file")));
    }

    /** The rights of Grav's admin and of its API (admin2) an admin of the grid has: the ones of a super user of the site. */
    public const GRAV_ADMIN = ['admin' => ['login', 'super'], 'api' => ['login', 'super']];

    /**
     * The access of an account with Grav's admin rights (GRAV_ADMIN) when its avatar is an admin of the grid, without them otherwise
     * (taken back once its level drops below); its other rights as they are.
     *
     * @param array<string,mixed> $access the access of the account (Grav's)
     * @return array<string,mixed>
     */
    public static function gravAdmin(array $access, bool $admin): array
    {
        foreach (self::GRAV_ADMIN as $scope => $rights) {
            foreach ($rights as $right) {
                if ($admin) {
                    $access[$scope][$right] = true;
                } else {
                    unset($access[$scope][$right]);
                }
            }
            if (! $admin && ($access[$scope] ?? null) === []) {
                unset($access[$scope]);
            }
        }

        return $access;
    }

    /**
     * The route of Grav's admin the site has (admin2, else the classic admin, enabled), null without one.
     *
     * @param array<string,mixed> $plugins the config of the plugins (Grav's `plugins`)
     */
    public static function gravAdminRoute(array $plugins): ?string
    {
        foreach (['admin2', 'admin'] as $admin) {
            if (($plugins[$admin]['enabled'] ?? false) === true) {
                return '/'.trim((string) ($plugins[$admin]['route'] ?? '/admin'), '/');
            }
        }

        return null;
    }

    /** Whether an avatar of this level reads the admin pages (adminLevel()). */
    public static function readsAdmin(int $level, array $config): bool
    {
        return $level >= self::adminLevel($config);
    }

    /** The refusals of the engine at a sign-in that end it: no other account of the site signs in with the avatar's name. */
    public const REFUSED = ['w4os.error_account_disabled', 'w4os.error_account_banned', 'w4os.error_email_unconfirmed'];

    /**
     * Whether a session that comes back by its remember-me token goes on, for the avatar as the engine has it now: only an active
     * one; a banned, disabled or pending avatar, or one the engine no longer has (null), is refused.
     *
     * @param ?array<string,mixed> $avatar
     */
    public static function resumes(?array $avatar): bool
    {
        return $avatar !== null && ($avatar['status'] ?? null) === 'active';
    }

    /**
     * The visitor as a Grav user, once the engine signed the avatar in. Its access: the site, the admin pages from the level of the
     * setting (`site.w4os.manage`, readsAdmin()); then each page, action and field from its own level (the contract's, level()).
     *
     * @param array<string,mixed> $avatar
     * @param ?UserInterface $user the user to update (a session back by "remember me": the one the Login plugin signed in, its state
     *     kept), else the account of the avatar
     */
    public function user(array $avatar, ?UserInterface $user = null): UserInterface
    {
        $user ??= $this->grav['accounts']->load(strtolower((string) ($avatar['slug'] ?? $avatar['uuid'])));
        $granted = self::accessOf($avatar);
        $user->set('fullname', (string) $avatar['name']);
        $user->set('email', (string) ($avatar['email'] ?? ''));
        $user->set('state', 'enabled');
        $user->set('provider', 'w4os');
        // The level every check reads (level()), its user level as it is, whether it owns the grid
        $user->set('w4os', ['uuid' => (string) $avatar['uuid'], 'name' => (string) $avatar['name']] + $granted);
        $access = (array) $user->get('access', []);
        $access['site']['login'] = true;
        $manage = self::readsAdmin($granted['level'], $this->config());
        $access['site']['w4os']['manage'] = $manage;
        // The admins of the grid manage the site too (Grav's admin and its API), as long as their level does
        $user->set('access', self::gravAdmin($access, $manage));
        // Its picture, the avatar's (Grav's avatar field, the one its admins show), else none: its initials
        $this->accountPicture($user, (string) ($avatar['slug'] ?? $avatar['uuid']), $avatar['profileImage'] ?? null);
        if (($this->config()['save_accounts'] ?? false) || $this->grav['config']->get('plugins.api.enabled', false)) {
            $user->save();
        }

        return $user;
    }

    /** @return ?array{uuid: string, name: string, level: int, userLevel?: int, owner?: bool} the avatar of the visitor, null when none signed in */
    public function avatar(): ?array
    {
        $user = $this->grav['user'] ?? null;
        $avatar = $user && $user->authenticated && $user->authorized ? $user->get('w4os') : null;

        return is_array($avatar) && ($avatar['uuid'] ?? '') !== '' ? $avatar : null;
    }

    /**
     * The page of the site where an avatar's profile is shown (its route "profile", /profile/First.Last), the one of the avatar
     * signed in by default; its account page when the site has no profile page.
     */
    public function profilePage(?string $name = null): string
    {
        $name ??= (string) ($this->avatar()['name'] ?? '');
        if ($name === '' || ($this->routes()['profile'] ?? '/') === '/') {
            return $this->url('account');
        }

        return $this->url('profile').'/'.rawurlencode(str_replace(' ', '.', trim($name)));
    }

    /**
     * Where an avatar lands once signed in: back on the page of the plugin that sent it to sign in, or on the protected page of the
     * site that showed the sign-in form itself (the one the Login plugin kept); from the sign-in page, its own profile, whatever
     * page the Login plugin kept on the way (its after_login route, the last page seen).
     *
     * @param ?string $asked the page of the plugin that sent the visitor to sign in
     * @param bool $onLoginPage whether the visitor signed in on the sign-in page
     * @param ?string $kept the page the Login plugin kept (session redirect_after_login)
     */
    public static function landing(?string $asked, bool $onLoginPage, ?string $kept, string $profile): string
    {
        return ($asked ?: null) ?? (! $onLoginPage && $kept ? $kept : $profile);
    }

    /**
     * Where the visitor changes a profile, when they may: their own (the account page), any for a manager (the edit page); null
     * otherwise.
     *
     * @param array<string,mixed> $avatar
     */
    public function profileEditUrl(array $avatar): ?string
    {
        $uuid = (string) ($avatar['uuid'] ?? '');
        if ($uuid === '' || ($current = $this->avatar()) === null) {
            return null;
        }
        if ($uuid === $current['uuid']) {
            return $this->url('account');
        }

        return $this->can('avatars') && isset($this->routes()['edit']) ? $this->url('edit').'?'.http_build_query(['avatar' => $uuid]) : null;
    }

    /**
     * The slogan of the site in the language of the page: the setting `slogan` of the theme, a text or one by language (else the
     * default language's), '' without one.
     */
    public function slogan(): string
    {
        $slogan = $this->grav['config']->get('theme.slogan');
        if (is_array($slogan)) {
            $language = $this->grav['language'];
            $default = (string) ($language->getDefault() ?: 'en');
            $slogan = $slogan[(string) ($language->getActive() ?: $default)] ?? $slogan[$default] ?? '';
        }

        return trim((string) $slogan);
    }

    /** The title of the site as it writes it (site.title, its accents kept), else the name of its grid (ASCII, as its grid info sends it). */
    public function title(): string
    {
        $title = trim((string) $this->grav['config']->get('site.title', ''));

        return $title !== '' ? $title : $this->gridName();
    }

    /** The languages most avatars speak, besides the ones of the site, suggested for the languages of a profile. */
    public const USUAL_LANGUAGES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt', 'cy', 'pl', 'sv', 'ru', 'ja', 'zh', 'ko', 'ar', 'tr'];

    /**
     * The names of the languages to suggest for a profile, each in its own language (Français, English, Deutsch): the site's
     * first, then the usual ones.
     *
     * @return list<string>
     */
    public function languageNames(): array
    {
        $language = $this->grav['language'];
        $names = [];
        foreach (array_unique([...array_map('strval', (array) $language->getLanguages()), ...self::USUAL_LANGUAGES]) as $code) {
            $locale = str_replace('-', '_', $code);
            $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage($locale, $locale) : '';
            $name = $name !== '' && $name !== $code ? $name : (string) \Grav\Common\Language\LanguageCodes::getNativeName($code);
            $name !== '' && $names[] = mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
        }

        return array_values(array_unique($names));
    }

    /** The pages of the admin menu, for the managers of the grid: their route (routes) and the text of their title. */
    public const ADMIN_PAGES = [
        'dashboard' => ['label' => 'w4os.dashboard_title', 'icon' => 'ti ti-layout-dashboard'],
        'settings_grid' => ['label' => 'w4os.settings_menu_grid', 'icon' => 'ti ti-world-cog'],
        'simulators' => ['label' => 'w4os.simulators_title', 'icon' => 'ti ti-server'],
        'regions' => ['label' => 'w4os.regions_title', 'icon' => 'ti ti-map'],
        'settings_map' => ['label' => 'w4os.settings_menu_map', 'icon' => 'ti ti-map-2', 'parent' => 'regions'],
        'manage' => ['label' => 'w4os.manage_title', 'icon' => 'ti ti-users'],
        'models' => ['label' => 'w4os.settings_models', 'icon' => 'ti ti-shirt', 'parent' => 'manage'],
        'flux' => ['label' => 'w4os.flux_menu', 'icon' => 'ti ti-message-circle', 'parent' => 'manage'],
        'settings_avatars' => ['label' => 'w4os.settings_avatars', 'icon' => 'ti ti-user-cog', 'parent' => 'manage'],
        'settings_search' => ['label' => 'w4os.settings_menu_search', 'icon' => 'ti ti-search', 'parent' => 'services'],
        'settings_guide' => ['label' => 'w4os.settings_menu_guide', 'icon' => 'ti ti-map-pin', 'parent' => 'services'],
        'settings_economy' => ['label' => 'w4os.settings_menu_economy', 'icon' => 'ti ti-coin', 'parent' => 'services'],
        'settings_offline' => ['label' => 'w4os.settings_menu_offline', 'icon' => 'ti ti-mail', 'parent' => 'services'],
        'settings_assets' => ['label' => 'w4os.settings_menu_assets', 'icon' => 'ti ti-photo', 'parent' => 'services'],
        'settings' => ['label' => 'w4os.admin_site_settings', 'icon' => 'ti ti-settings', 'parent' => 'site'],
        'shortcodes' => ['label' => 'w4os.admin_shortcodes_menu', 'icon' => 'ti ti-code', 'parent' => 'site'],
        'health' => ['label' => 'w4os.admin_health_title', 'icon' => 'ti ti-heartbeat'],
    ];

    /**
     * The sections of the admin menu that are no page: an entry of the menu leading to the first of the pages whose `parent` they
     * are (Site: its pages belong together), or a heading above them (`heading`: Services, pages with nothing in common).
     */
    public const ADMIN_GROUPS = [
        'services' => ['label' => 'w4os.group_services', 'heading' => true],
        'site' => ['label' => 'w4os.admin_menu_site', 'icon' => 'ti ti-world-www'],
    ];

    /**
     * The shortcodes of the blocks of the helpers, by the names of w4os-wp (its blocks/*.php; [new-avatars] for its new users), each to
     * the block it shows: one shortcode by block, its options as attributes ([popular-places max=5]), the closing slash left free.
     */
    public const BLOCK_SHORTCODES = [
        'grid-info' => 'grid_info', 'grid-status' => 'grid_status', 'avatar-profile' => 'profile', 'popular-places' => 'popular_places',
        'web-search' => 'search', 'destination-guide' => 'destination_guide', 'new-avatars' => 'new_users', 'directory-info' => 'directory_info',
        'motd' => 'motd', 'grid-map' => 'map',
    ];

    /** The route of the about page a grid announces (its grid info), the home where the site has none. */
    public const ABOUT_ROUTE = '/about';

    /** The route of the news page of a site, whose last posts the home page of the grid shows. */
    public const NEWS_ROUTE = '/news';

    /**
     * The shortcodes of the site and the blocks of the helpers they show, for the page of the shortcodes (as the page Shortcodes of
     * w4os-wp): the blocks first, in its order, then the shortcodes of the site; each its code, the catalog key of what it shows, the
     * block it previews with the arguments of a short preview, its options (each its catalog key).
     */
    public const SHORTCODES = [
        ['code' => '[grid-info]', 'help' => 'block_grid_info_help', 'block' => 'grid-info', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[grid-status]', 'help' => 'block_grid_status_help', 'block' => 'grid-status', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[avatar-profile]', 'help' => 'block_profile_help', 'block' => 'profile', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level'], ['option' => 'name', 'help' => 'shortcode_option_name']]],
        ['code' => '[popular-places]', 'help' => 'block_popular_places_help', 'block' => 'popular-places', 'preview' => ['title' => '', 'level' => 'h3', 'count' => 3], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level'], ['option' => 'max=5', 'help' => 'shortcode_option_count'], ['option' => 'include-hypergrid', 'help' => 'shortcode_option_include_hypergrid'], ['option' => 'include-landsales', 'help' => 'shortcode_option_include_landsales']]],
        ['code' => '[web-search]', 'help' => 'block_search_help', 'block' => 'search', 'preview' => ['title' => ''], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[motd]', 'help' => 'block_motd_help', 'block' => 'motd', 'preview' => ['level' => 'h3'], 'options' => [['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[grid-map]', 'help' => 'block_map_help', 'block' => 'map', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[destination-guide]', 'help' => 'block_destination_guide_help', 'block' => 'destination-guide', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[new-avatars]', 'help' => 'block_new_avatars_help', 'block' => 'new-users', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[directory-info]', 'help' => 'block_directory_info_help', 'block' => 'directory-info', 'preview' => ['title' => '', 'level' => 'h3'], 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h3', 'help' => 'shortcode_option_level']]],
        ['code' => '[login-uri]', 'help' => 'admin_shortcode_uri_help', 'options' => [['option' => 'add-grid', 'help' => 'admin_shortcode_option_add_grid']]],
        ['code' => '[getting-started]', 'help' => 'admin_shortcode_getting_started_help', 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'content', 'help' => 'admin_shortcode_option_content'], ['option' => 'footer', 'help' => 'admin_shortcode_option_footer']]],
        ['code' => '[tp-link]', 'help' => 'admin_shortcode_tp_link_help', 'options' => [['option' => 'destination', 'help' => 'admin_shortcode_option_destination'], ['option' => 'label', 'help' => 'admin_shortcode_option_label']]],
        ['code' => '[hypergrid-visit]', 'help' => 'admin_shortcode_hypergrid_visit_help', 'options' => [['option' => 'title', 'help' => 'shortcode_option_title'], ['option' => 'level=h2', 'help' => 'shortcode_option_level']]],
        ['code' => '[base_url]', 'help' => 'admin_shortcode_base_url_help', 'options' => []],
        ['code' => '[w4os-home]…[/w4os-home]', 'help' => 'admin_shortcode_home_help', 'options' => []],
        ['code' => '[w4os-guest]…[/w4os-guest]', 'help' => 'admin_shortcode_guest_help', 'options' => []],
        ['code' => '[w4os-register]…[/w4os-register]', 'help' => 'admin_shortcode_register_help', 'options' => []],
        ['code' => '[grid-name]', 'help' => 'admin_shortcode_grid_name_help', 'options' => []],
        ['code' => '[site-title]', 'help' => 'admin_shortcode_site_title_help', 'options' => []],
        ['code' => '[site-slogan]', 'help' => 'admin_shortcode_slogan_help', 'options' => []],
    ];

    /** The admin pages that show an operation of the engine, not a list: they open to whom the contract lets do it (x-access). */
    public const ADMIN_OPERATIONS = ['health' => ['get', '/grids/{grid}/health'], 'models' => ['get', '/grids/{grid}/settings/models'], 'new' => ['post', '/grids/{grid}/avatars'],
        'settings_economy' => ['get', '/grids/{grid}/settings/economy'], 'settings_search' => ['get', '/grids/{grid}/settings/search'],
        'settings_guide' => ['get', '/grids/{grid}/settings/guide'], 'settings_offline' => ['get', '/grids/{grid}/settings/offline'],
        'settings_assets' => ['get', '/grids/{grid}/settings/assets'], 'settings_grid' => ['get', '/grids/{grid}/settings/grid'],
        'settings_avatars' => ['get', '/grids/{grid}/settings/avatars'], 'settings_map' => ['get', '/grids/{grid}/settings/map']];

    /** The group of settings of the engine a settings page of a service shows (settings_economy: economy), null for another page. */
    public static function serviceGroup(string $type): ?string
    {
        return str_starts_with($type, 'settings_') && isset(self::ADMIN_OPERATIONS[$type]) ? substr($type, strlen('settings_')) : null;
    }

    /** The section of the admin a page is in: its list for the page of one item. */
    public const ADMIN_SECTIONS = ['region' => 'regions', 'simulator' => 'simulators', 'edit' => 'manage', 'new' => 'manage'];

    /** The items an admin page lists: the page shows to whom the contract lets read them. */
    public const ADMIN_TYPES = ['manage' => 'avatars', 'edit' => 'avatars', 'regions' => 'regions', 'region' => 'regions', 'simulators' => 'simulators', 'simulator' => 'simulators', 'flux' => 'posts'];

    /**
     * The entries of the admin menu: the admin pages of the plugin, then the ones other plugins add (event onW4osAdminMenu, its
     * `items`: label and url). None for a visitor who is not a manager.
     *
     * @return list<array{label: string, url: string}>
     */
    public function adminMenu(): array
    {
        if (! $this->isManager()) {
            return [];
        }
        [$current] = $this->pageAt($this->grav['uri']->path());
        $section = self::ADMIN_SECTIONS[$current] ?? $current;
        $items = [];
        foreach (self::ADMIN_PAGES as $type => $page) {
            isset($this->routes()[$type]) && (! isset(self::ADMIN_TYPES[$type]) || $this->can(self::ADMIN_TYPES[$type], 'read'))
                && (! isset(self::ADMIN_OPERATIONS[$type]) || $this->canOperation($type))
                && ($type !== 'settings' || $this->canSettings())
                && $items[] = ['type' => $type, 'label' => $this->translate($page['label']), 'url' => $this->path($type, true), 'icon' => $page['icon'], 'active' => $type === $section]
                    + (isset($page['parent']) ? ['parent' => $page['parent']] : []);
        }
        // The pages of the site, in Grav's admin (named so), for whom has its rights (the admins of the grid, gravAdmin())
        $route = self::gravAdminRoute((array) $this->grav['config']->get('plugins', []));
        $user = $this->grav['user'] ?? null;
        $route !== null && $user && ($user->authorize('api.super') || $user->authorize('admin.super'))
            && $items[] = ['type' => 'grav_pages', 'label' => $this->translate('w4os.admin_grav_pages'), 'url' => rtrim($this->grav['uri']->rootUrl(false), '/').$route.'/pages', 'icon' => 'ti ti-files', 'active' => false, 'parent' => 'site'];
        $event = new \RocketTheme\Toolbox\Event\Event(['items' => $items]);
        $this->grav->fireEvent('onW4osAdminMenu', $event);

        $groups = array_map(fn (array $group) => ['label' => $this->translate($group['label'])] + $group, self::ADMIN_GROUPS);

        return self::menuTree(array_values((array) $event['items']), $this->translate('w4os.admin_menu_list'), $groups);
    }

    /**
     * The entries of a menu with the ones that belong to another (`parent`, its type) under it (`children`): the page of the parent
     * first, named as a list ($list, not the name of the parent), then theirs. A parent that is a section of $groups (no page: its
     * label, its icon) is an entry of its own where its first page was, leading to that page (`url`), its pages under it; marked
     * `group`. A section marked `heading` is a heading (`heading`) before its first page instead, its pages left in the menu after
     * it, the next entry out of it set apart (`gap`). A parent is open (active) when one of its pages is the page shown. An entry
     * whose parent is in neither stays where it is.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string, array{label: string, icon?: string, heading?: bool}> $groups
     * @return list<array<string,mixed>>
     */
    public static function menuTree(array $items, ?string $list = null, array $groups = []): array
    {
        $types = array_column($items, 'type');
        $parent = fn (array $item) => isset($item['parent']) && (in_array($item['parent'], $types, true) || isset($groups[$item['parent']])) ? $item['parent'] : null;
        $children = [];
        foreach ($items as $item) {
            ($of = $parent($item)) !== null && $children[$of][] = $item;
        }
        $active = fn (array $entries) => in_array(true, array_column($entries, 'active'), true);
        $menu = [];
        $heading = null;
        foreach ($items as $item) {
            $of = $parent($item);
            // A section of pages with nothing in common: its heading before its first page, its pages after it, the next entry apart
            if ($of !== null && ! in_array($of, $types, true) && ($groups[$of]['heading'] ?? false)) {
                ($children[$of][0]['type'] ?? null) === ($item['type'] ?? null) && $menu[] = ['type' => $of, 'label' => $groups[$of]['label'], 'heading' => true];
                $heading = $of;
                $menu[] = $item;
                continue;
            }
            $heading !== null && $of !== $heading && $item['gap'] = true;
            $heading = null;
            // A section: its entry where its first page was
            if ($of !== null && ! in_array($of, $types, true)) {
                ($children[$of][0]['type'] ?? null) === ($item['type'] ?? null) && $menu[] = ['type' => $of, 'label' => $groups[$of]['label'], 'url' => $item['url'] ?? '', 'group' => true,
                    'active' => $active($children[$of]), 'children' => $children[$of]] + (isset($groups[$of]['icon']) ? ['icon' => $groups[$of]['icon']] : [])
                    + (($item['gap'] ?? false) ? ['gap' => true] : []);
                continue;
            }
            if ($of !== null) {
                continue;
            }
            if (isset($children[$item['type'] ?? null])) {
                $item['children'] = [['label' => $list ?? $item['label']] + $item, ...$children[$item['type']]];
                $item['active'] = $active($item['children']);
            }
            $menu[] = $item;
        }

        return $menu;
    }

    /**
     * The identity of the site for its admin pages: its title, its logo (adminLogo(): the plugin's setting admin.logo, else the
     * brand of the site, never the theme's; else the logo of the grid), its accent color (admin.color, else the theme's accent-color)
     * for Tabler.
     *
     * @return array{title: string, logo: ?string, home: string, color?: string, rgb?: string, lt-rgb?: string}
     */
    public function brand(): array
    {
        $theme = (array) $this->grav['config']->get('themes.'.$this->grav['config']->get('system.pages.theme'), []);
        $admin = (array) ($this->config()['admin'] ?? []);
        $logo = (string) self::adminLogo((string) ($admin['logo'] ?? ''), (array) ($this->config()['brand'] ?? []), fn (string $path) => is_file(GRAV_ROOT.'/'.$path));
        $logo !== '' && ! str_contains($logo, '://') && ! str_starts_with($logo, '/') && $logo = $this->grav['base_url_relative'].'/'.$logo;
        $logo === '' && $logo = (string) ($this->engine()->gridLogo() ?? '');
        // The icon of the site, in the folded sidebar
        $icon = (string) self::adminLogo('', (array) ($this->config()['brand'] ?? []), fn (string $path) => is_file(GRAV_ROOT.'/'.$path), 'favicon');

        return [
            'title' => (string) $this->grav['config']->get('site.title', ''),
            'logo' => $logo === '' ? null : $logo,
            'icon' => $icon === '' ? null : $this->grav['base_url_relative'].'/'.$icon,
            'home' => $this->grav['base_url_relative'] ?: '/',
        ] + (self::brandColor((string) ($admin['color'] ?? '') ?: (string) ($theme['accent-color'] ?? '')) ?? []);
    }

    /**
     * The styles the site adds to a page, for the admin: the plugin's own and the Form plugin's (its drop zones), none of the other
     * plugins of the site (their front-end looks stay out of Tabler).
     */
    public static function adminCss(string $html): string
    {
        return (string) preg_replace_callback('~<link\b[^>]*>\s*~i', fn (array $m) => preg_match('~href="[^"]*/plugins/(w4os|form)/~', $m[0]) ? $m[0] : '', $html);
    }

    /**
     * The logo of the admin, whatever the theme of the site: the admin's own setting (an address), else the logo of the brand of the
     * site (its file, a path of the site); null without one (the logo of the grid then). Its icon (`kind` favicon): the favicon of
     * the brand of the site.
     *
     * @param array<string,mixed> $brand the brand of the site (plugins.w4os.brand)
     */
    public static function adminLogo(string $setting, array $brand, \Closure $exists, string $kind = 'logo'): ?string
    {
        if (trim($setting) !== '') {
            return trim($setting);
        }
        $files = (array) ($brand[$kind] ?? []);
        $path = ltrim((string) (($files === [] ? [] : (array) reset($files))['path'] ?? ''), '/');

        return $path !== '' && ! str_contains($path, '..') && $exists($path) ? $path : null;
    }

    /**
     * A color of the site as the primary color of Tabler: the hex color, its RGB and the RGB of its light tint (10 % on white);
     * null for what is not a hex color.
     *
     * @return ?array{color: string, rgb: string, lt-rgb: string}
     */
    public static function brandColor(?string $hex): ?array
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim((string) $hex), $m) !== 1) {
            return null;
        }
        $hex = strtolower(strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1]);
        $rgb = array_map('hexdec', str_split($hex, 2));

        return [
            'color' => "#$hex",
            'rgb' => implode(',', $rgb),
            'lt-rgb' => implode(',', array_map(fn (int $c) => (int) round($c * .1 + 255 * .9), $rgb)),
        ];
    }


    /** Whether the visitor reads the admin pages (readsAdmin()). */
    public function isManager(): bool
    {
        $user = $this->grav['user'] ?? null;

        return $this->avatar() !== null && (bool) $user->authorize('site.w4os.manage');
    }

    /** The level the access of the visitor is decided by, as the engine gave it at sign-in (accessOf()); 0 for nobody. */
    public function level(): int
    {
        return (int) ($this->avatar()['level'] ?? 0);
    }

    /**
     * Whether the visitor, in the admin pages, may read (`read`) or change (`change`) the items of a type, as the contract says for
     * their level (Access::allows()).
     */
    /** Whether the visitor does an operation of the engine an admin page shows (ADMIN_OPERATIONS), as the contract says. */
    public function canOperation(string $page): bool
    {
        [$method, $path] = self::ADMIN_OPERATIONS[$page] ?? [null, null];

        return $method !== null && $this->isManager() && Access::allows($this->openApi()->access($method, $path), $this->level());
    }

    /**
     * The button above a list that makes an item (the engine's create) for whom may, at the page of its form: its address and the
     * catalog key of its label; null otherwise.
     *
     * @return ?array{url: string, label: string}
     */
    public function createLink(string $type): ?array
    {
        $form = $type === 'avatars' && isset($this->routes()['new']) && $this->canOperation('new') ? $this->openApi()->creationOf($type) : null;

        return $form === null ? null : ['url' => $this->path('new', true), 'label' => $form['title']];
    }

    public function can(string $type, string $what = 'change'): bool
    {
        $access = $this->isManager() ? ($this->openApi()->itemOf($type)[$what] ?? $this->openApi()->listOf($type)[$what] ?? null) : null;

        return $access !== null && Access::allows($access, $this->level());
    }

    /** The fields of a theme the brand of the site goes to, by setting of the site, in the order a theme may have them. */
    /** The folders of the brand of the site (its logo, its favicon), in its data (served, kept across upgrades). */
    public const BRAND_FOLDERS = ['logo' => 'user://data/theme/images/logo', 'favicon' => 'user://data/theme/images/favicon'];

    /**
     * What some themes show of their demo site, hidden through their own options (settings and visibility, never their styles):
     * Hadron's menu links (getgrav.org, its option custommenus) and its untranslated language icons, Learn2's link to the repository of its demo.
     */
    public const THEME_SETTINGS = [
        'hadron' => ['custommenus.enabled' => false, 'langswitcher.format' => 'short'],
        'learn2' => ['github.position' => 'off'],
    ];

    /** The fields of a theme that name a file of its logo or its favicon. */
    public const THEME_FILE_FIELDS = ['custom_logo', 'custom_logo_mobile', 'custom_favicon', 'favicon'];

    /**
     * The settings the plugin gives a theme for this request: its logo or favicon fields cleared where their files are missing (a
     * demo site's), its THEME_SETTINGS; nothing the site set itself in its own configuration of the theme.
     *
     * @param array<string,mixed> $config the configuration of the theme as Grav has it
     * @param list<string> $own the settings (dotted) the site's own configuration of the theme has
     * @return array<string,mixed> setting (dotted) => its value
     */
    public static function themeFixes(string $theme, array $config, array $own, \Closure $exists): array
    {
        $fixes = [];
        foreach (self::THEME_FILE_FIELDS as $field) {
            $files = $config[$field] ?? null;
            if (! is_array($files) || $files === []) {
                continue;
            }
            $found = array_filter($files, fn ($file, $path) => $exists(ltrim((string) (is_array($file) ? ($file['path'] ?? $path) : $path), '/')), ARRAY_FILTER_USE_BOTH);
            $found === [] && $fixes[$field] = null;
        }
        $fixes += self::THEME_SETTINGS[$theme] ?? [];

        return array_diff_key($fixes, array_flip($own));
    }

    /** The settings the plugin gives the active theme (themeFixes()), in memory for this request, nothing written. */
    public function applyThemeFixes(): void
    {
        $theme = (string) $this->grav['config']->get('system.pages.theme');
        $config = (array) $this->grav['config']->get("themes.$theme", []);
        foreach (self::themeFixes($theme, $config, $this->ownThemeSettings($theme), fn (string $path) => is_file(GRAV_ROOT.'/'.$path)) as $key => $value) {
            $this->grav['config']->set("themes.$theme.$key", $value);
            $this->grav['config']->set("theme.$key", $value);
        }
    }

    public const BRAND_FIELDS = ['logo' => ['custom_logo'], 'favicon' => ['custom_favicon', 'favicon']];

    /**
     * Where the file of a brand field of a theme goes, as Grav reads it: its own stream (theme://images/logo), its theme@ token as
     * a stream (Grav's older theme@:/images, theme://images), a plain folder in user/ (Hadron's media/logo, its own defaults in
     * user/media/logo); theme://images when it names none.
     */
    public static function brandDestination(string $destination): string
    {
        $destination = trim($destination);
        if ($destination === '') {
            return 'theme://images';
        }
        if (preg_match('~^(?:theme@|@theme@?):?/*(.*)$~', $destination, $token)) {
            return rtrim('theme://'.$token[1], '/');
        }

        return str_contains($destination, '://') ? $destination : 'user://'.trim($destination, '/');
    }

    /** Where admin2 keeps the files of its logo and its icon (its branding, admin-next.ui.branding: their names). */
    public const ADMIN_NEXT_MEDIA = 'user://media/admin-next';

    /** The prefix of the copies the plugin makes there: the files of a choice made in an admin itself have none. */
    public const ADMIN_BRAND_PREFIX = 'w4os-';

    /**
     * The logo and the icon of the site, for Grav's admins: its own settings (Réglages du site, plugins.w4os.brand), else the ones of
     * its theme (its file fields, BRAND_FIELDS: custom_logo; custom_favicon, favicon); paths from the root of the site, null for none.
     *
     * @param array<string,mixed> $brand the brand of the site (plugins.w4os.brand)
     * @param array<string,mixed> $theme the settings of the active theme
     * @return array{logo: ?string, favicon: ?string}
     */
    public static function effectiveBrand(array $brand, array $theme): array
    {
        $path = function (mixed $files): ?string {
            $file = is_array($files) && $files !== [] ? reset($files) : null;
            $path = is_array($file) ? ltrim((string) ($file['path'] ?? ''), '/') : '';

            return $path === '' || str_contains($path, '..') ? null : $path;
        };
        $found = [];
        foreach (self::BRAND_FIELDS as $setting => $fields) {
            $found[$setting] = $path($brand[$setting] ?? null);
            foreach ($fields as $field) {
                $found[$setting] ??= $path($theme[$field] ?? null);
            }
        }

        return ['logo' => $found['logo'] ?? null, 'favicon' => $found['favicon'] ?? null];
    }

    /**
     * The name of the copy of a file of the brand in the media of admin2: its kind and a mark of the file (its path, its size, its
     * date), its extension; another file, or the same one changed, another name (and no browser keeping the old one).
     */
    public static function brandCopyName(string $kind, string $path, int $size, int $time): string
    {
        return self::ADMIN_BRAND_PREFIX.$kind.'-'.substr(md5("$path|$size|$time"), 0, 8).'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Admin2's branding with the logo of the site (light and dark) and its icon, by the names of their copies in its media; null for
     * none (admin2's own again). A choice made in admin2 itself stays: its text instead of a logo, its own logo, its own icon.
     *
     * @param array<string,mixed> $branding admin2's (admin-next.ui.branding)
     * @return array<string,mixed>
     */
    public static function adminNextBranding(array $branding, ?string $logo, ?string $favicon): array
    {
        $ours = fn (mixed $file) => (string) $file === '' || str_starts_with((string) $file, self::ADMIN_BRAND_PREFIX);
        if (($branding['mode'] ?? 'default') !== 'text' && $ours($branding['logoLight'] ?? '') && $ours($branding['logoDark'] ?? '')) {
            $branding['mode'] = $logo === null ? 'default' : 'custom';
            $branding['logoLight'] = (string) $logo;
            $branding['logoDark'] = (string) $logo;
        }
        $ours($branding['favicon'] ?? '') && $branding['favicon'] = (string) $favicon;

        return $branding;
    }

    /**
     * The classic admin's whitelabel with the logo of the site, on its sign-in screen and in its menu (it has no setting of its icon:
     * the one of its theme); empty for none. A logo chosen in the admin itself stays.
     *
     * @param array<string,mixed> $whitelabel its whitelabel (plugins.admin.whitelabel)
     * @param ?string $logo the path of the copy, from the root of the site
     * @return array<string,mixed>
     */
    public static function adminWhitelabel(array $whitelabel, ?string $logo): array
    {
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
        $file = $logo === null ? [] : [$logo => ['name' => basename($logo), 'type' => $mime[strtolower(pathinfo($logo, PATHINFO_EXTENSION))] ?? 'image/png', 'size' => 0, 'path' => $logo]];
        foreach (['logo_login', 'logo_custom'] as $field) {
            $current = array_keys((array) ($whitelabel[$field] ?? []));
            $theirs = $current !== [] && ! str_starts_with(basename((string) $current[0]), self::ADMIN_BRAND_PREFIX);
            $theirs || $whitelabel[$field] = $file;
        }

        return $whitelabel;
    }

    /**
     * The logo and the icon of the site where Grav's admins read them, installed or not (installed later, they show them): copies in
     * admin2's media, its branding (user/config/admin-next.yaml) and the classic admin's whitelabel (user/config/plugins/admin.yaml),
     * their other settings as they are. Written only when the files of the site changed: a request with nothing new writes nothing.
     */
    public function syncAdminBrand(): void
    {
        $config = $this->grav['config'];
        $theme = (string) $config->get('system.pages.theme');
        // The theme's own settings, as its file and the site's give them (never the copies applyBrand() puts in them for a request)
        $own = [];
        foreach (array_merge(...array_values(self::BRAND_FIELDS)) as $field) {
            $value = $this->savedSetting("themes/$theme", $field) ?? $this->themeDefault($theme, $field);
            $value === null || $own[$field] = $value;
        }
        $effective = self::effectiveBrand((array) $config->get('plugins.w4os.brand', []), $own);
        $names = [];
        foreach ($effective as $kind => $path) {
            $file = $path === null ? '' : GRAV_ROOT.'/'.$path;
            $names[$kind] = $file !== '' && is_file($file) ? self::brandCopyName($kind, $path, (int) filesize($file), (int) filemtime($file)) : null;
        }
        $branding = (array) $config->get('admin-next.ui.branding', []);
        $whitelabel = (array) $config->get('plugins.admin.whitelabel', []);
        $media = rtrim((string) $this->grav['locator']->findResource(self::ADMIN_NEXT_MEDIA, false, true), '/');
        $logoPath = $names['logo'] === null ? null : $media.'/'.$names['logo'];
        $newBranding = self::adminNextBranding($branding, $names['logo'], $names['favicon']);
        $newWhitelabel = self::adminWhitelabel($whitelabel, $logoPath);
        $copies = array_filter($names);
        $missing = array_filter($copies, fn (string $name) => ! is_file(GRAV_ROOT."/$media/$name"));
        if ($newBranding === $branding && $newWhitelabel === $whitelabel && $missing === []) {
            return;
        }
        // The copies, the ones of before removed
        $folder = GRAV_ROOT."/$media";
        is_dir($folder) || mkdir($folder, 0775, true);
        foreach (glob("$folder/".self::ADMIN_BRAND_PREFIX.'*') ?: [] as $old) {
            in_array(basename($old), $copies, true) || unlink($old);
        }
        foreach ($copies as $kind => $name) {
            is_file("$folder/$name") || copy(GRAV_ROOT.'/'.$effective[$kind], "$folder/$name");
        }
        // Their settings, the one key of each in its file of the configuration, as Grav's admin writes them (writeSettings())
        $newBranding === $branding || $this->writeSettings('admin-next', fn (array $content) => self::withSetting($content, 'ui.branding', $newBranding));
        $newWhitelabel === $whitelabel || $this->writeSettings('plugins/admin', fn (array $content) => self::withSetting($content, 'whitelabel', $newWhitelabel));
        $config->set('admin-next.ui.branding', $newBranding);
        $config->set('plugins.admin.whitelabel', $newWhitelabel);
    }

    /** The switches of a theme that show its logo once it is set (Editorial's custom_logo_enabled). */
    public const BRAND_SWITCHES = ['custom_logo_enabled'];

    /** The other fields of a theme that show the logo of the site too (Quark2's custom_logo_mobile, in its menu on a phone). */
    public const LOGO_FIELDS = ['custom_logo_mobile'];

    /**
     * The fields of the active theme the brand of the site goes to (its logo, its favicon): the first of each its blueprint has,
     * none for a theme without them (its own choice stands).
     *
     * @param \Closure(string $field): bool $has whether the blueprint of the theme has a field
     * @return array<string,string> setting of the site => field of the theme
     */
    public static function brandFields(\Closure $has): array
    {
        $fields = [];
        foreach (self::BRAND_FIELDS as $setting => $candidates) {
            foreach ($candidates as $field) {
                if (! isset($fields[$setting]) && $has($field)) {
                    $fields[$setting] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * The switches of the active theme that show its logo once set, on.
     *
     * @param \Closure(string $field): bool $has
     * @return array<string,bool>
     */
    public static function brandSwitches(\Closure $has): array
    {
        return array_fill_keys(array_values(array_filter(self::BRAND_SWITCHES, $has)), true);
    }

    /**
     * The fields of the active theme's blueprint, as Grav resolves it (its imports too): the definition of one by its name, null
     * when it has none.
     */
    public function themeField(string $field): ?array
    {
        $theme = $this->grav['themes']->get((string) $this->grav['config']->get('system.pages.theme'));
        $property = $theme?->blueprints()->schema()->getProperty($field);

        return is_array($property) ? $property : null;
    }

    /** Whether the visitor changes the settings of the site: a manager of the level the engine says (x-site, Access::site()). */
    public function canSettings(): bool
    {
        return $this->isManager() && Access::allows($this->openApi()->siteAccess('settings'), $this->level());
    }

    /**
     * A setting of Grav saved in the configuration of the site (config://<file>.yaml, user/config), as Grav's admin writes it: the
     * one key, nothing else of the configuration (what Grav or its environment adds stays out of the file).
     */
    public function saveSetting(string $file, string $key, mixed $value): void
    {
        $this->writeSettings($file, fn (array $config) => self::withSetting($config, $key, $value));
        $this->grav['config']->set("$file.$key", $value);
    }

    /** A setting saved in the configuration of the site (config://<file>.yaml, user/config), null when it has none. */
    public function savedSetting(string $file, string $key): mixed
    {
        $path = (string) $this->grav['locator']->findResource("config://$file.yaml");

        return $path === '' ? null : \Grav\Common\Utils::getDotNotation((array) \Grav\Common\File\CompiledYamlFile::instance($path)->content(), $key);
    }

    /** A setting of a theme as the theme gives it (its <theme>.yaml), null when it has none. */
    public function themeDefault(string $theme, string $key): mixed
    {
        $own = (string) $this->grav['locator']->findResource("themes://$theme/$theme.yaml");

        return $own === '' ? null : \Grav\Common\Utils::getDotNotation((array) \Grav\Common\File\CompiledYamlFile::instance($own)->content(), $key);
    }

    /** A setting removed from the configuration of the site (config://<file>.yaml), the sections it empties with it. */
    public function removeSetting(string $file, string $key): void
    {
        if ($this->grav['locator']->findResource("config://$file.yaml") !== false) {
            $this->writeSettings($file, fn (array $config) => self::withoutSetting($config, $key));
        }
    }

    /**
     * The brand of the site (its logo and its favicon, the plugin's setting brand) as the settings of a theme, as the upload of
     * the theme's own fields would write them (brandFields()): each the copy made in the theme (brandCopy()), by its name and its
     * path; the switches that show the logo on with it. Nothing for what the site has not set, the theme has no field for or could
     * not be copied.
     *
     * @param array<string,array<string,array<string,mixed>>> $brand setting (logo, favicon) => its files, by their paths
     * @param array<string,string> $fields setting => the field of the theme
     * @param array<string,string> $copies setting => the path of its copy in the theme
     * @param array<string,bool> $switches the switches of the theme (brandSwitches())
     * @param list<string> $logoFields the other fields of the theme that show the logo (LOGO_FIELDS it has)
     * @return array<string,mixed> field of the theme => its value
     */
    public static function themeBrand(array $brand, array $fields, array $copies, array $switches, array $logoFields = []): array
    {
        $settings = [];
        foreach ($fields as $setting => $field) {
            $files = (array) ($brand[$setting] ?? []);
            $file = $files === [] ? null : reset($files);
            $copy = (string) ($copies[$setting] ?? '');
            if (! is_array($file) || $copy === '') {
                continue;
            }
            $settings[$field] = [$copy => ['name' => basename($copy), 'type' => (string) ($file['type'] ?? ''), 'path' => $copy]];
            $setting === 'logo' && $settings += $switches + array_fill_keys($logoFields, $settings[$field]);
        }

        return $settings;
    }

    /**
     * A file of the brand of the site copied into the folder a theme takes it in (paths of the site, from its root), as the
     * upload of the theme's field puts it: by its own name, by "site-<name>" beside a different file of the theme of that name
     * (never over it); made again when missing (a theme updated). Null when the file is missing or the copy fails.
     */
    public static function brandCopy(string $root, string $source, string $folder): ?string
    {
        $from = "$root/".ltrim($source, '/');
        if (! is_file($from)) {
            return null;
        }
        $folder = trim($folder, '/');
        $same = fn (string $to) => is_file($to) && filesize($to) === filesize($from) && md5_file($to) === md5_file($from);
        foreach ([basename($source), 'site-'.basename($source)] as $i => $name) {
            $to = "$root/$folder/$name";
            if ($same($to)) {
                return "$folder/$name";
            }
            // The theme's own file of that name stays; the second name is the site's own
            if ($i === 0 && file_exists($to)) {
                continue;
            }
            if ((is_dir(dirname($to)) || @mkdir(dirname($to), 0775, true)) && @copy($from, $to)) {
                return "$folder/$name";
            }

            return null;
        }

        return null;
    }

    /**
     * The brand of the site given to the active theme for this request, as the upload of its own fields would (its files copied
     * where the theme takes them, its settings in memory): the same logo and favicon whatever the theme, until the site sets none
     * (each theme its own then).
     */
    public function applyBrand(): void
    {
        $brand = array_filter((array) $this->grav['config']->get('plugins.w4os.brand', []));
        // Without a brand, only a theme whose own files were replaced by it before (to put them back)
        if ($brand === [] && ! is_dir($this->keptFiles((string) $this->grav['config']->get('system.pages.theme')))) {
            return;
        }
        // Whatever a theme declares, the site's pages open: the theme as it is then, the cause in Grav's log
        try {
            $this->giveBrand($brand);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('w4os: the brand of the site not given to the theme: '.$e->getMessage());
        }
    }

    /** @param array<string,mixed> $brand the brand of the site, as applyBrand() gives it */
    private function giveBrand(array $brand): void
    {
        $theme = (string) $this->grav['config']->get('system.pages.theme');
        $has = fn (string $field) => $this->themeField($field) !== null;
        $fields = self::brandFields($has);
        $copies = [];
        $folders = [];
        foreach ($fields as $setting => $field) {
            $files = (array) ($brand[$setting] ?? []);
            $file = $files === [] ? null : reset($files);
            $folder = (string) ($this->grav['locator']->findResource(self::brandDestination((string) ($this->themeField($field)['destination'] ?? '')), false, true) ?: '');
            $folders[$setting] = $folder;
            if (is_array($file) && $folder !== '' && ($copy = self::brandCopy(GRAV_ROOT, (string) ($file['path'] ?? ''), $folder)) !== null) {
                $copies[$setting] = $copy;
            }
        }
        // Both the theme's settings and their copy as the active theme's (config.theme, what theme_var() reads)
        $logoFields = array_values(array_filter(self::LOGO_FIELDS, $has));
        foreach (self::themeBrand($brand, $fields, $copies, self::brandSwitches($has), $logoFields) as $field => $value) {
            $this->grav['config']->set("themes.$theme.$field", $value);
            $this->grav['config']->set("theme.$field", $value);
        }
        // A theme without a field for it, the file it takes it from (Woo's images/logo.png): the site's there, the theme's own
        // put back without it
        $folder = (string) $this->grav['locator']->findResource("themes://$theme", true);
        foreach ($folder === '' ? [] : $this->fixedFilesOf($folder) as $setting => $file) {
            $files = (array) ($brand[$setting] ?? []);
            $source = $files === [] ? null : GRAV_ROOT.'/'.ltrim((string) (reset($files)['path'] ?? ''), '/');
            // A file of a stream of the site (Hypertext's image://favicon/favicon.png, the site's images), else of the theme
            $target = str_contains($file, '://') ? (string) $this->grav['locator']->findResource($file, true, true) : "$folder/$file";
            // A theme with a field for it takes the file of the field, unless its templates read a fixed name in the folder of the
            // field (Hypertext: its favicon field, its favicon.png)
            if (isset($fields[$setting]) && dirname($target) !== rtrim(GRAV_ROOT.'/'.ltrim($folders[$setting] ?? '', '/'), '/')) {
                continue;
            }
            $kept = $this->keptFiles($theme).'/'.str_replace('://', '/', $file);
            $target === '' || self::brandOver($source !== null && is_file($source) ? $source : null, $target, $kept);
        }
        // The options that show it (Hypertext's structure.use_favicon), for what the site has
        $given = array_keys(array_filter($brand, fn ($files) => is_array($files) && $files !== []));
        foreach (self::brandSettings($theme, $given, $this->ownThemeSettings($theme)) as $key => $value) {
            $this->grav['config']->set("themes.$theme.$key", $value);
            $this->grav['config']->set("theme.$key", $value);
        }
    }

    /** The settings (dotted) the site's own configuration of a theme has, and the sections that hold them. */
    private function ownThemeSettings(string $theme): array
    {
        $file = (string) $this->grav['locator']->findResource("config://themes/$theme.yaml");
        $own = $file === '' ? [] : array_keys(\Grav\Common\Utils::arrayFlattenDotNotation((array) \Grav\Common\File\CompiledYamlFile::instance($file)->content()));
        foreach ($own as $key) {
            for ($dot = strrpos($key, '.'); $dot !== false; $dot = strrpos($key, '.')) {
                $key = substr($key, 0, $dot);
                $own[] = $key;
            }
        }

        return array_values(array_unique($own));
    }

    /**
     * What of the brand of the site the active theme shows: the logo, the favicon, through a field of its own or the file it takes
     * them from (fixedBrandFiles()).
     *
     * @return array<string,true>
     */
    public function brandSupport(): array
    {
        $theme = (string) $this->grav['config']->get('system.pages.theme');
        $folder = (string) $this->grav['locator']->findResource("themes://$theme", true);
        $found = self::brandFields(fn (string $field) => $this->themeField($field) !== null) + ($folder === '' ? [] : $this->fixedFilesOf($folder));

        return array_map(fn () => true, $found);
    }

    /** What the brand of the site keeps of a theme's own files it replaced (user/data/w4os/theme-files/<theme>). */
    private function keptFiles(string $theme): string
    {
        return rtrim((string) $this->grav['locator']->findResource('user://data', true), '/')."/w4os/theme-files/$theme";
    }

    /** @return array<string,string> fixedBrandFiles() of a theme's folder, kept in Grav's cache until its templates change */
    private function fixedFilesOf(string $folder): array
    {
        $cache = $this->grav['cache'];
        $key = 'w4os-brand-files-'.md5($folder.'@'.(int) @filemtime("$folder/templates"));
        $files = $cache->fetch($key);
        if (! is_array($files)) {
            $files = self::fixedBrandFiles($folder);
            $cache->save($key, $files);
        }

        return $files;
    }

    /** The files a theme takes its logo or its favicon from by a fixed name (no field for them): the place the brand goes. */
    public const FIXED_BRAND_FILES = [
        'logo' => ['images/logo.png', 'images/logo.svg', 'img/logo.png'],
        'favicon' => ['images/favicon.png', 'images/favicon.ico', 'img/favicon.png', 'img/favicon.ico', 'image://favicon/favicon.png'],
    ];

    /** The options of a theme that show what the brand of the site gives it (Hypertext shows its fixed favicon when told to). */
    public const BRAND_SETTINGS = ['hypertext' => ['favicon' => ['structure.use_favicon' => true]]];

    /**
     * The options of a theme to turn on with the brand of the site (BRAND_SETTINGS), for what the site has (logo, favicon), unless
     * the site set them itself.
     *
     * @param list<string> $has what of the brand the site has: logo, favicon
     * @param list<string> $own the settings (dotted) the site's own configuration of the theme has
     * @return array<string,mixed>
     */
    public static function brandSettings(string $theme, array $has, array $own): array
    {
        $settings = [];
        foreach (self::BRAND_SETTINGS[$theme] ?? [] as $setting => $values) {
            in_array($setting, $has, true) && $settings += $values;
        }

        return array_diff_key($settings, array_flip($own));
    }

    /**
     * The files a theme takes its logo and its favicon from by a fixed name: the first of FIXED_BRAND_FILES its templates name.
     *
     * @return array<string,string> setting => the file, from the theme's folder
     */
    public static function fixedBrandFiles(string $folder): array
    {
        $templates = '';
        if (is_dir("$folder/templates")) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$folder/templates", \FilesystemIterator::SKIP_DOTS)) as $file) {
                str_ends_with($file->getFilename(), '.twig') && $templates .= (string) file_get_contents($file->getPathname());
            }
        }
        $found = [];
        foreach (self::FIXED_BRAND_FILES as $setting => $files) {
            foreach ($files as $file) {
                if (str_contains($templates, $file)) {
                    $found[$setting] = $file;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * A theme's fixed file given the site's (its own kept the first time and after an update of the theme), or its own put back
     * when the site has none ($source null). What it wrote is known by its checksum, kept beside the theme's own.
     */
    public static function brandOver(?string $source, string $target, string $kept): void
    {
        $mark = "$kept.w4os";
        $ours = is_file($mark) ? trim((string) file_get_contents($mark)) : '';
        $current = is_file($target) ? (string) md5_file($target) : '';
        if ($source === null) {
            if (is_file($kept) && $current === $ours) {
                @copy($kept, $target);
            }
            @unlink($kept);
            @unlink($mark);

            return;
        }
        $wanted = (string) md5_file($source);
        if ($current === $wanted) {
            return;
        }
        // The theme's own (first, or after an update of the theme), not what the brand wrote before
        if ($current !== '' && $current !== $ours) {
            (is_dir(dirname($kept)) || @mkdir(dirname($kept), 0775, true)) && @copy($target, $kept);
        }
        if ((is_dir(dirname($target)) || @mkdir(dirname($target), 0775, true)) && @copy($source, $target)) {
            (is_dir(dirname($mark)) || @mkdir(dirname($mark), 0775, true)) && file_put_contents($mark, $wanted);
        }
    }

    /**
     * Whether a setting is the default: the same files (a list of files by their paths, what Grav's file fields keep), the same
     * value, or nothing set where the default is nothing.
     */
    public static function isDefault(mixed $value, mixed $default): bool
    {
        if ($default === null) {
            return in_array($value, [null, [], false, ''], true);
        }
        if (is_array($value) && is_array($default) && ! (array_is_list($value) && array_is_list($default))) {
            $paths = array_keys($value);
            $defaults = array_keys($default);
            sort($paths);
            sort($defaults);

            return $paths === $defaults;
        }

        return $value === $default;
    }

    /**
     * A configuration without one setting, by its dotted key, and without the sections that leaves empty.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function withoutSetting(array $config, string $key): array
    {
        [$first, $rest] = array_pad(explode('.', $key, 2), 2, null);
        if ($rest !== null && is_array($config[$first] ?? null)) {
            $config[$first] = self::withoutSetting($config[$first], $rest);
        }
        if ($rest === null || ($config[$first] ?? null) === []) {
            unset($config[$first]);
        }

        return $config;
    }

    /**
     * One configuration file of the site rewritten, then read at once by the next request: the file Grav reads (the first of
     * config:// that has it), else one of the site's own folder (user/config). Never a new one in the folder of an environment that
     * Grav 1.7 makes for its own files (user/localhost/config, its security.yaml): a setting removed there would stay as the site's
     * own file has it.
     */
    private function writeSettings(string $file, \Closure $change): void
    {
        $locator = $this->grav['locator'];
        $path = $locator->findResource("config://$file.yaml", true) ?: $locator->findResource('user://config', true, true)."/$file.yaml";
        $yaml = \Grav\Common\File\CompiledYamlFile::instance($path);
        $yaml->save($change((array) $yaml->content()));
        $yaml->free();
        // The next request reads it at once, the site's pages too: Grav's own clearing of its cache (the list of the configuration
        // files it otherwise trusts for system.cache.check.interval, the compiled files and templates), as its admin offers it
        \Grav\Common\Cache::clearCache('standard');
    }

    /**
     * A configuration with one setting changed, by its dotted key (`pages.theme`).
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function withSetting(array $config, string $key, mixed $value): array
    {
        $node = &$config;
        foreach (explode('.', $key) as $part) {
            is_array($node[$part] ?? null) || $node[$part] = [];
            $node = &$node[$part];
        }
        $node = $value;

        return $config;
    }

    /** Keep the API key typed on the settings page in user-data (not in the configuration): see onAdminSave. */
    public function saveApiKey(string $key): void
    {
        $file = $this->grav['locator']->findResource(self::SECRET, true, true);
        @mkdir(dirname($file), 0770, true);
        file_put_contents($file, (string) json_encode(['api_key' => $key]));
        @chmod($file, 0640);
    }

    /** Keep the password of the database typed on the settings page in user-data (not in the configuration): see onAdminSave. */
    public function saveDatabasePassword(string $password): void
    {
        $file = $this->grav['locator']->findResource(self::DATABASE_SECRET, true, true);
        @mkdir(dirname($file), 0770, true);
        file_put_contents($file, (string) json_encode(['password' => $password]));
        @chmod($file, 0640);
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /** The configuration (environment) wins, then the key kept in user-data. */
    private function apiKey(): string
    {
        return trim((string) ($this->config()['api_key'] ?? '')) ?: $this->secret(self::SECRET, 'api_key');
    }

    /** A value kept in a file of user-data. */
    private function secret(string $stream, string $key): string
    {
        $file = $this->grav['locator']->findResource($stream, true);
        $saved = $file && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return trim((string) ($saved[$key] ?? ''));
    }

    /**
     * A text of the language files, the values put in its %s (Grav's translations, vsprintf).
     *
     * @param list<scalar> $vars
     */
    public function translate(string $key, array $vars = []): string
    {
        return (string) $this->grav['language']->translate(array_merge([$key], array_values($vars)));
    }

    /**
     * A notice of the engine (what is left to do, a note) in the language of the page: its text in the language files
     * (`notice_<code>`, its {params} put in), else its English detail; an old notice is its text already.
     *
     * @param string|array{code?: string, params?: array<string,scalar>, detail?: string} $notice
     */
    public function notice(string|array $notice): string
    {
        return self::noticeText($notice, fn (string $key) => $this->translate($key));
    }

    /**
     * The text of a notice (Site::notice()), with the translation of a key of the language files given.
     *
     * @param string|array{code?: string, params?: array<string,scalar>, detail?: string} $notice
     * @param \Closure(string $key): string $translate the text of a key, the key itself when there is none
     */
    public static function noticeText(string|array $notice, \Closure $translate): string
    {
        if (is_string($notice)) {
            return $notice;
        }
        $key = 'w4os.notice_'.str_replace('-', '_', (string) ($notice['code'] ?? ''));
        $text = isset($notice['code']) ? $translate($key) : $key;
        if ($text === $key || $text === '') {
            return (string) ($notice['detail'] ?? ($notice['code'] ?? ''));
        }
        $params = (array) ($notice['params'] ?? []);

        return strtr($text, array_combine(array_map(fn ($name) => '{'.$name.'}', array_keys($params)), array_map('strval', array_values($params))) ?: []);
    }

    /**
     * A count in the language of the page, in the form its number takes there (the engine's catalog: `%d region`, `%d regions`);
     * a text without forms as the language files have it.
     *
     * @param string $key a key of the language files (`w4os.regions_count`)
     */
    public function count(string $key, int $n): string
    {
        Bundled::library();
        $name = (string) preg_replace('/^w4os\./', '', $key);
        $language = (string) ($this->grav['language']->getLanguage() ?: $this->grav['language']->getDefault() ?: 'en');
        if (! class_exists(\W4OS\Engine\I18n\Catalog::class) || ! isset(\W4OS\Engine\I18n\Catalog::SINGULARS[$name])) {
            return self::counted($this->translate($key), $n, $language);
        }

        return self::counted(\W4OS\Engine\I18n\Catalog::plural($language, $name, $n), $n, $language);
    }

    /** A text that counts (%d prims) with its number said in the language of the page, as the others (2 150 prims). */
    public static function counted(string $text, int $n, string $language = 'en'): string
    {
        return sprintf(str_replace('%d', '%s', $text), self::number($n, $language, 0));
    }

    /**
     * Whether a page takes the plugin's styles for the site (css/w4os.css): the pages of the admin have their own (Tabler,
     * css/admin.css).
     *
     * @param ?string $template the template of the page
     * @param ?string $page what the page shows (`w4os` of its front matter)
     */
    public static function siteStyles(?string $template, ?string $page): bool
    {
        return $template !== 'w4os-admin';
    }

    /**
     * How long the site waits for an answer (seconds): a read is quick; a change waits for its end, as the engine does (an instance
     * stopped, then started again).
     */
    public static function timeout(string $method): int
    {
        return strtoupper($method) === 'GET' ? 15 : 300;
    }

    /**
     * Grav's HTTP client, for the engine and the blocks of the helpers.
     *
     * @return array{int, string, array<string, list<string>>} the status, the body, the headers
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $response = Client::getClient(['timeout' => self::timeout($method)])->request($method, $url, ['headers' => $headers] + ($body === null ? [] : ['body' => $body]));

        return [$response->getStatusCode(), $response->getContent(false), $response->getHeaders(false)];
    }

    /**
     * A block of the helpers of the grid, and its notices to the visitor (their header X-Helpers-Notices).
     *
     * @return array{html: string, notices: list<array{level: string, text: string}>}
     * @throws BlockUnavailable when the helpers do not give it (no answer, an error)
     */
    private function block(string $url): array
    {
        try {
            [$status, $body, $headers] = $this->send('GET', $url, ['Accept' => 'text/html', 'Accept-Language' => $this->acceptLanguage()], null);
        } catch (\Throwable $e) {
            throw new BlockUnavailable(Blocks::reason($e->getMessage()), 'admin_blocks_no_answer', [Blocks::reason($e->getMessage())], $e);
        }

        return Blocks::answer($status, $body, $headers);
    }

    /** The HTML of a block, its notices to the visitor added to the messages of the site (shown at the start of the page). */
    private function noticed(mixed $block): ?string
    {
        if (! is_array($block)) {
            return is_string($block) ? $block : null;
        }
        foreach ((array) ($block['notices'] ?? []) as $notice) {
            $level = in_array($notice['level'] ?? '', ['info', 'warning', 'error'], true) ? $notice['level'] : 'info';
            $this->grav['messages']->add(htmlspecialchars((string) ($notice['text'] ?? '')), $level);
        }

        return (string) ($block['html'] ?? '');
    }

    private function get(string $url, string $accept = 'text/html'): ?string
    {
        try {
            [$status, $body] = $this->send('GET', $url, ['Accept' => $accept, 'Accept-Language' => $this->acceptLanguage()], null);

            return $status === 200 ? $body : null;
        } catch (\Throwable $e) {
            $this->grav['log']->warning("w4os: $url: ".$e->getMessage());

            return null;
        }
    }

    /** The language of the visitor, for what the helpers show (their blocks, in their own translations). */
    private function acceptLanguage(): string
    {
        $language = $this->grav['language'];
        $active = (string) ($language->getActive() ?: ($language->getDefault() ?: 'en'));

        return $active === 'en' ? 'en' : "$active, en;q=0.5";
    }

    /** The contract of the engine (its OpenAPI document), read: kept an hour. */
    public function openApi(): Contract
    {
        return new Contract((array) ($this->cached('openapi', function () {
            try {
                return $this->engine()->openapi() ?: null;
            } catch (EngineException) {
                return null;
            }
        }, 3600) ?? []));
    }

    /** The avatar models kept in the cache, forgotten in every language (their rule changed). */
    public function forgetModels(): void
    {
        foreach (['', ...(array) $this->grav['config']->get('system.languages.supported', [])] as $language) {
            $this->grav['cache']->delete('w4os-'.md5('models|'.$language));
        }
    }

    /** A value kept in the cache of Grav for the time of the setting "cache" (or the one given, in seconds); null is not kept. */
    /**
     * The key of a value kept in the cache of Grav: for a language, and for the grid the settings name (another engine, API, grid or
     * login address is another grid, never the old one's values).
     *
     * @param array<string,mixed> $config the settings of the plugin
     */
    public static function cacheId(string $key, string $language, array $config): string
    {
        $source = [$config['engine'] ?? 'remote', $config['api_url'] ?? '', $config['grid'] ?? '', $config['bundled']['login'] ?? ''];

        return 'w4os-'.md5($key.'|'.$language.'|'.json_encode($source));
    }

    private function cached(string $key, \Closure $value, ?int $lifetime = null): mixed
    {
        $cache = $this->grav['cache'];
        $id = self::cacheId($key, (string) $this->grav['language']->getActive(), $this->config());
        $kept = $cache->fetch($id);
        if ($kept !== false && $kept !== null) {
            return $kept;
        }
        $kept = $value();
        $kept === null || $cache->save($id, $kept, $lifetime ?? max(0, (int) ($this->config()['cache'] ?? 60)));

        return $kept;
    }
}
