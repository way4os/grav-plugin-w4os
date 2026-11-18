<?php

namespace W4os\Grav;

use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Page\Page;
use Grav\Common\User\Interfaces\UserInterface;
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
     * The engine: the API of the grid (setting "engine: remote", its address and key), or the engine the plugin bundles ("bundled").
     */
    public function engine(): Engine
    {
        if ($this->engine !== null) {
            return $this->engine;
        }
        if ($bundled = $this->bundled()) {
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

        return $this->bundled = new Bundled($settings, $root, fn (string $url) => $this->get($url, 'text/xml'), $cache);
    }

    /** Whether the site serves the helpers of the grid itself (setting "serve_helpers", with the bundled engine). */
    public function servesHelpers(): bool
    {
        return (bool) ($this->config()['serve_helpers'] ?? false) && $this->bundled() !== null;
    }

    /** Answers a request for the helpers of the grid, when the site serves them and the address is one of theirs; false otherwise. */
    public function helpers(string $path): bool
    {
        if (! $this->servesHelpers() || ! $this->bundled()->serves($path)) {
            return false;
        }
        $this->bundled()->serveHelpers();

        return true;
    }

    /**
     * Answers the address of a picture of the grid (route "assets") with the bundled engine: the pictures of the models.
     *
     * @return ?array{int, string, array<string,string>} the status, the body, the headers; null when it is not such an address
     */
    public function asset(string $path): ?array
    {
        $route = $this->routes()['assets'] ?? '';
        $bundled = $route === '' ? null : $this->bundled();
        if ($bundled === null || preg_match('#^'.preg_quote($route, '#').'/([0-9a-fA-F-]{36})\.(jpg|png)$#', $path, $m) !== 1) {
            return null;
        }

        // From the web: the engine locked, it answers what it says is public only
        return $bundled->publicRoute('GET', '/grids/'.rawurlencode($bundled->nick())."/assets/{$m[1]}.{$m[2]}");
    }

    public function forms(): Forms
    {
        return new Forms($this->engine(), $this->config(), fn (string $type) => $this->url($type));
    }

    public function blocks(): Blocks
    {
        // The blocks of the helpers the site serves, or of the bundled engine without other helpers; else the ones of the helpers of the grid
        $bundled = $this->bundled();
        if ($bundled && ($this->servesHelpers() || trim((string) ($this->config()['url'] ?? '')) === '')) {
            return new Blocks(fn (string $name, array $args) => $this->noticed(Blocks::kept($name)
                ? $this->cached("block $name ".json_encode($args).$this->profileVersion($name, $args), fn () => $bundled->rendered($name, $args))
                : $bundled->rendered($name, $args)));
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
     * The address of a logo of the engine the plugin bundles (a file of OpenSim\Assets\Logos::FILES), null when it has none.
     */
    public function logo(string $file): ?string
    {
        Bundled::library();
        if (! class_exists(\OpenSim\Assets\Logos::class) || ! in_array($file, \OpenSim\Assets\Logos::FILES, true)) {
            return null;
        }
        $path = 'vendor/magicoli/opensim-engine/'.\OpenSim\Assets\Logos::FOLDER.'/'.$file;

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
        $placeholder = class_exists(\OpenSim\Assets\WebAssets::class)
            ? ($person ? \OpenSim\Assets\WebAssets::PERSON : \OpenSim\Assets\WebAssets::PLACEHOLDER)
            : '00000000-0000-0000-0000-000000000000';

        return $this->engine()->assetUrl($none ? $placeholder : (string) $uuid).($person ? '?default=person' : '');
    }

    /**
     * The avatar models, with the address of their picture, kept for the time of the setting "cache".
     *
     * @return list<array<string,mixed>>
     */
    public function models(): array
    {
        $models = $this->cached('models', function () {
            try {
                return array_map(fn (array $m) => $m + ['image' => $this->engine()->assetUrl((string) $m['picture'])], $this->engine()->models());
            } catch (EngineException $e) {
                $this->grav['log']->warning('w4os: avatar models: '.$e->getMessage());

                return null;
            }
        });

        return is_array($models) ? $models : [];
    }

    /** The name of the grid, kept for the time of the setting "cache"; empty when the engine cannot tell it. */
    public function gridName(): string
    {
        return $this->info('grid name', fn () => $this->engine()->gridName());
    }

    /** The login URI of the grid (host:port), kept for the time of the setting "cache"; empty when the engine cannot tell it. */
    public function loginUri(): string
    {
        return $this->info('login uri', fn () => $this->engine()->loginUri());
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
        $routes = array_map(fn ($route) => '/'.trim((string) $route, '/'), (array) ($this->config()['routes'] ?? []));
        if (! ($this->config()['register']['enabled'] ?? true)) {
            unset($routes['register'], $routes['confirm']);
        }

        return $routes;
    }

    /** The absolute address of a page of the plugin, in the language of the visitor (or none: the pictures). */
    /** The path of a route of the plugin on this site, without the host: a page shows it whatever address it was reached at. */
    public function path(string $type, bool $language = false): string
    {
        return rtrim($this->grav['uri']->rootUrl(false), '/').($language ? $this->grav['language']->getLanguageURLPrefix() : '').($this->routes()[$type] ?? '/');
    }

    public function url(string $type, bool $language = true): string
    {
        $route = $this->routes()[$type] ?? '/';

        return rtrim($this->grav['uri']->rootUrl(true), '/').($language ? $this->grav['language']->getLanguageURLPrefix() : '').$route;
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
        $type = array_search($path, $this->routes(), true);
        if ($type !== false) {
            return [$type, ''];
        }
        foreach (self::PREFIX_PAGES as $prefix) {
            $route = $this->routes()[$prefix] ?? '';
            if ($route !== '' && $route !== '/' && str_starts_with($path, "$route/")) {
                return [$prefix, rawurldecode(substr($path, strlen($route) + 1))];
            }
        }

        return [false, ''];
    }

    /** Whether a viewer asks the page (its user agent, as the engine tells it): the page then shows its content alone. */
    public function isViewer(): bool
    {
        return $this->client() === 'viewer';
    }

    /** The class of the visitor, as the engine tells it from its user agent (Destination::client()): `viewer` or `browser`. */
    public function client(): string
    {
        Bundled::library();

        return class_exists(\OpenSim\Hypergrid\Destination::class) && method_exists(\OpenSim\Hypergrid\Destination::class, 'client')
            ? \OpenSim\Hypergrid\Destination::client((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
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
                && ($type !== 'settings' || $this->canSettings())
                && $sections[] = ['type' => $type, 'route' => $this->routes()[$type], 'label' => $page['label']];
        }

        return $sections;
    }

    /** The menu of the site (setting `menu`, menuEntries()) as pages of the tree of Grav, under its root, their children under them. */
    public function addMenu(): void
    {
        if (! ($this->config()['menu'] ?? true)) {
            return;
        }
        $root = $this->grav['pages']->root();
        foreach (self::menuEntries($this->routes(), $this->avatar() !== null, $this->isManager() ? $this->adminSections() : []) as $entry) {
            $this->menuPage($entry, $root);
        }
    }

    /**
     * The title of the page of an entry of the menu: its label, for the themes that list the pages by their title (Apple Notes);
     * the admin keeps the title of its dashboard.
     */
    public static function menuTitleKey(array $entry): string
    {
        return $entry['type'] === 'admin' ? self::titleKey('dashboard') : (string) $entry['label'];
    }

    /** An entry of the menu as a page of the tree, visible, under its parent; a page of the site at its route wins. */
    private function menuPage(array $entry, $parent): void
    {
        $pages = $this->grav['pages'];
        if ($pages->find($entry['route'])) {
            return;
        }
        $page = $this->pluginPage($entry['type'] === 'admin' ? 'dashboard' : $entry['type'], $entry['route']);
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

    /**
     * The blocks of the helpers the text of a page shows through its shortcodes ([w4os block="..."], [w4os-home]), by their names
     * in the helpers (grid_status), for their files before the theme writes its head.
     *
     * @return list<string>
     */
    public static function shortcodeBlocks(string $raw): array
    {
        $blocks = str_contains($raw, '[w4os-home') ? ['popular_places', 'destination_guide', 'grid_info', 'grid_status', 'motd'] : [];
        preg_match_all('/\[w4os\s[^\]]*\bblock="([^"]+)"/', $raw, $found);

        return array_values(array_unique([...$blocks, ...array_map(fn ($name) => str_replace('-', '_', strtolower($name)), $found[1])]));
    }

    /** The key of the title of a page of the plugin, in the catalog or the texts of the plugin. */
    public static function titleKey(string $type): string
    {
        return match ($type) {
            'account' => 'w4os.edit_profile',
            'search' => 'w4os.search',
            'settings' => 'w4os.admin_site_settings',
            default => 'w4os.'.$type.'_title',
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

    public function addPage(string $path): void
    {
        [$type] = $this->pageAt($path);
        $pages = $this->grav['pages'];
        $found = $pages->find($path);
        // A page of the site at that route wins; the plugin's own already there (the entry of the menu) is the one shown
        if ($type === false || ! is_file(dirname(__DIR__)."/pages/$type.md") || ($found && ! isset($found->header()->w4os))) {
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
        foreach (array_keys(\OpenSim\Models\Avatar::LEVELS) as $level) {
            $levels[$level] = 'w4os.'.\OpenSim\I18n\Catalog::roleKey(\OpenSim\Models\Avatar::roleOf($level));
        }

        return $levels;
    }

    /** The level the admin pages open from: the setting when it is one of adminLevels(), else customer service. */
    public static function adminLevel(array $config): int
    {
        $level = is_numeric($config['admin_level'] ?? null) ? (int) $config['admin_level'] : -1;

        return isset(self::adminLevels()[$level]) ? $level : \OpenSim\Models\Avatar::GOD_CUSTOMER_SERVICE;
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
        $level = (int) ($avatar['level'] ?? 0);
        $user->set('fullname', (string) $avatar['name']);
        $user->set('email', (string) ($avatar['email'] ?? ''));
        $user->set('state', 'enabled');
        $user->set('provider', 'w4os');
        $user->set('w4os', ['uuid' => (string) $avatar['uuid'], 'name' => (string) $avatar['name'], 'level' => (int) ($avatar['level'] ?? 0)]);
        $access = (array) $user->get('access', []);
        $access['site']['login'] = true;
        $access['site']['w4os']['manage'] = self::readsAdmin($level, $this->config());
        $user->set('access', $access);
        if (($this->config()['save_accounts'] ?? false) || $this->grav['config']->get('plugins.api.enabled', false)) {
            $user->save();
        }

        return $user;
    }

    /** @return ?array{uuid: string, name: string, level: int} the avatar of the visitor, null when none signed in */
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
        'simulators' => ['label' => 'w4os.simulators_title', 'icon' => 'ti ti-server'],
        'regions' => ['label' => 'w4os.regions_title', 'icon' => 'ti ti-map'],
        'manage' => ['label' => 'w4os.manage_title', 'icon' => 'ti ti-users'],
        'settings' => ['label' => 'w4os.admin_site_settings', 'icon' => 'ti ti-settings'],
    ];

    /** The section of the admin a page is in: its list for the page of one item. */
    public const ADMIN_SECTIONS = ['region' => 'regions', 'edit' => 'manage'];

    /** The items an admin page lists: the page shows to whom the contract lets read them. */
    public const ADMIN_TYPES = ['manage' => 'avatars', 'edit' => 'avatars', 'regions' => 'regions', 'region' => 'regions', 'simulators' => 'simulators'];

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
                && ($type !== 'settings' || $this->canSettings())
                && $items[] = ['label' => $this->translate($page['label']), 'url' => $this->path($type, true), 'icon' => $page['icon'], 'active' => $type === $section];
        }
        $event = new \RocketTheme\Toolbox\Event\Event(['items' => $items]);
        $this->grav->fireEvent('onW4osAdminMenu', $event);

        return array_values((array) $event['items']);
    }

    /**
     * The identity of the site for its admin pages: its title, its logo (the plugin's setting admin.logo, else the theme's
     * custom_logo, else the logo of the grid), its accent color (admin.color, else the theme's accent-color) for Tabler.
     *
     * @return array{title: string, logo: ?string, home: string, color?: string, rgb?: string, lt-rgb?: string}
     */
    public function brand(): array
    {
        $theme = (array) $this->grav['config']->get('themes.'.$this->grav['config']->get('system.pages.theme'), []);
        $admin = (array) ($this->config()['admin'] ?? []);
        $logo = trim((string) ($admin['logo'] ?? ''));
        if ($logo === '' && is_array($theme['custom_logo'] ?? null) && ($first = reset($theme['custom_logo'])) && isset($first['name'])) {
            $logo = (string) $this->grav['locator']->findResource('theme://images/logo/'.$first['name'], false);
            $logo = $logo === '' ? '' : $this->grav['base_url_relative'].'/'.ltrim($logo, '/');
        }
        $logo === '' && $logo = (string) ($this->engine()->gridLogo() ?? '');

        return [
            'title' => (string) $this->grav['config']->get('site.title', ''),
            'logo' => $logo === '' ? null : $logo,
            'home' => $this->grav['base_url_relative'] ?: '/',
        ] + (self::brandColor((string) ($admin['color'] ?? '') ?: (string) ($theme['accent-color'] ?? '')) ?? []);
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

    /** The user level of the visitor, as the engine gave it at sign-in; 0 for nobody. */
    public function level(): int
    {
        return (int) ($this->avatar()['level'] ?? 0);
    }

    /**
     * Whether the visitor, in the admin pages, may read (`read`) or change (`change`) the items of a type, as the contract says for
     * their level (Access::allows()).
     */
    public function can(string $type, string $what = 'change'): bool
    {
        $access = $this->isManager() ? ($this->openApi()->itemOf($type)[$what] ?? $this->openApi()->listOf($type)[$what] ?? null) : null;

        return $access !== null && Access::allows($access, $this->level());
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
        $yaml = \Grav\Common\File\CompiledYamlFile::instance($this->grav['locator']->findResource("config://$file.yaml", true, true));
        $yaml->save(self::withSetting((array) $yaml->content(), $key, $value));
        $yaml->free();
        $this->grav['config']->set("$file.$key", $value);
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
        if (! class_exists(\OpenSim\I18n\Catalog::class) || ! isset(\OpenSim\I18n\Catalog::SINGULARS[$name])) {
            return $this->translate($key, [$n]);
        }
        $language = (string) ($this->grav['language']->getLanguage() ?: $this->grav['language']->getDefault() ?: 'en');

        return sprintf(\OpenSim\I18n\Catalog::plural($language, $name, $n), $n);
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
     * @return ?array{html: string, notices: list<array{level: string, text: string}>}
     */
    private function block(string $url): ?array
    {
        try {
            [$status, $body, $headers] = $this->send('GET', $url, ['Accept' => 'text/html', 'Accept-Language' => $this->acceptLanguage()], null);
        } catch (\Throwable $e) {
            $this->grav['log']->warning("w4os: $url: ".$e->getMessage());

            return null;
        }
        $notices = json_decode((string) ($headers['x-helpers-notices'][0] ?? ''), true);

        return $status === 200 ? ['html' => $body, 'notices' => is_array($notices) ? $notices : []] : null;
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

    /** A value kept in the cache of Grav for the time of the setting "cache" (or the one given, in seconds); null is not kept. */
    private function cached(string $key, \Closure $value, ?int $lifetime = null): mixed
    {
        $cache = $this->grav['cache'];
        $id = 'w4os-'.md5($key.'|'.$this->grav['language']->getActive());
        $kept = $cache->fetch($id);
        if ($kept !== false && $kept !== null) {
            return $kept;
        }
        $kept = $value();
        $kept === null || $cache->save($id, $kept, $lifetime ?? max(0, (int) ($this->config()['cache'] ?? 60)));

        return $kept;
    }
}
