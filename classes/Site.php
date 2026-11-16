<?php

namespace W4os\Grav;

use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Page\Page;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * The part that knows Grav: the settings and the secrets, the engine (the API of the grid through Grav's HTTP client, or the engine
 * the plugin bundles) and the blocks with Grav's cache, the pages of the plugin, the avatar of the visitor.
 */
final class Site
{
    /** The asset the engine answers with its placeholder picture. */
    private const NO_PICTURE = '201ce950-aa38-46d8-a8f1-4396e9d6be00';

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
        $url = trim((string) ($config['api_url'] ?? '')) ?: (trim((string) ($config['url'] ?? '')) === '' ? '' : rtrim($config['url'], '/').'/api');

        return $this->engine = new Engine($url, $this->apiKey(), trim((string) ($config['grid'] ?? '')), $this->send(...));
    }

    /** The engine and the helpers the plugin bundles, when the settings ask them and the vendor folder is there; null otherwise. */
    public function bundled(): ?Bundled
    {
        if ($this->bundled !== null || ($this->config()['engine'] ?? 'remote') !== 'bundled') {
            return $this->bundled;
        }
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        is_file($autoload) && require_once $autoload;
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

        return $bundled->route('GET', '/grids/'.rawurlencode($bundled->nick())."/assets/{$m[1]}.{$m[2]}");
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
            return new Blocks(fn (string $name, array $args) => $this->noticed($this->cached("block $name ".json_encode($args), fn () => $bundled->rendered($name, $args))));
        }

        return Blocks::remote((string) ($this->config()['url'] ?? ''), fn (string $url) => $this->noticed($this->cached($url, fn () => $this->block($url))));
    }

    /**
     * The address of a logo of the engine the plugin bundles (a file of OpenSim\Assets\Logos::FILES), null when it has none.
     */
    public function logo(string $file): ?string
    {
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        is_file($autoload) && require_once $autoload;
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

    /** The address of the profile picture of an avatar, the placeholder of the engine when it has none. */
    public function picture(?string $uuid): string
    {
        $none = $uuid === null || trim($uuid, "0- ") === '';

        return $this->engine()->assetUrl($none ? self::NO_PICTURE : $uuid);
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

    /** Whether a viewer asks the page (its user agent, as the helpers tell it): the page then shows its content alone. */
    public function isViewer(): bool
    {
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        is_file($autoload) && require_once $autoload;

        return class_exists(\OpenSim\Helpers\View\View::class) && method_exists(\OpenSim\Helpers\View\View::class, 'isViewer')
            && \OpenSim\Helpers\View\View::isViewer((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public function addPage(string $path): void
    {
        [$type] = $this->pageAt($path);
        $pages = $this->grav['pages'];
        if ($type === false || ! is_file(dirname(__DIR__)."/pages/$type.md") || $pages->find($path)) {
            return;
        }
        $page = new Page();
        $page->init(new \SplFileInfo(dirname(__DIR__).'/pages/'.$type.'.md'));
        $page->route($path);
        $page->slug(basename($path));
        $page->title($this->translate('w4os.'.$type.'_title'));
        $pages->addPage($page, $path);
        unset($this->grav['page']);
        $this->grav['page'] = $page;
    }

    /**
     * The visitor as a Grav user, once the engine signed the avatar in. Its access: the site, and the management of the avatars from
     * the level of the setting "manager_level".
     *
     * @param array<string,mixed> $avatar
     */
    public function user(array $avatar): UserInterface
    {
        $user = $this->grav['accounts']->load(strtolower((string) ($avatar['slug'] ?? $avatar['uuid'])));
        $manager = (int) ($avatar['level'] ?? 0) >= (int) ($this->config()['manager_level'] ?? 200);
        $user->set('fullname', (string) $avatar['name']);
        $user->set('email', (string) ($avatar['email'] ?? ''));
        $user->set('state', 'enabled');
        $user->set('provider', 'w4os');
        $user->set('w4os', ['uuid' => (string) $avatar['uuid'], 'name' => (string) $avatar['name'], 'level' => (int) ($avatar['level'] ?? 0)]);
        $access = (array) $user->get('access', []);
        $access['site']['login'] = true;
        $access['site']['w4os']['manage'] = $manager;
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

        return $this->isManager() && isset($this->routes()['edit']) ? $this->url('edit').'?'.http_build_query(['avatar' => $uuid]) : null;
    }

    /** The web page of the profile of an avatar (web-profile of the grid, [AGENT_NAME] as First.Last), null when the grid has none. */
    public function profileUrl(string $name): ?string
    {
        $pattern = $this->info('web_profile', fn () => $this->engine()->webProfile());

        return $pattern === '' ? null : str_replace('[AGENT_NAME]', rawurlencode(str_replace(' ', '.', trim($name))), $pattern);
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
     * The names of the languages to suggest for a profile, in the language of the page: the site's first, then the usual ones.
     *
     * @return list<string>
     */
    public function languageNames(): array
    {
        $language = $this->grav['language'];
        $page = (string) ($language->getActive() ?: ($language->getDefault() ?: 'en'));
        $names = [];
        foreach (array_unique([...array_map('strval', (array) $language->getLanguages()), ...self::USUAL_LANGUAGES]) as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage(str_replace('-', '_', $code), str_replace('-', '_', $page)) : '';
            $name = $name !== '' && $name !== $code ? $name : (string) \Grav\Common\Language\LanguageCodes::getNativeName($code);
            $name !== '' && $names[] = mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
        }

        return array_values(array_unique($names));
    }

    /** The pages of the admin menu, for the managers of the grid: their route (routes) and the text of their title. */
    public const ADMIN_PAGES = ['manage' => 'w4os.manage_title'];

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
        $items = [];
        foreach (self::ADMIN_PAGES as $type => $label) {
            isset($this->routes()[$type]) && $items[] = ['label' => $this->translate($label), 'url' => $this->path($type, true)];
        }
        $event = new \RocketTheme\Toolbox\Event\Event(['items' => $items]);
        $this->grav->fireEvent('onW4osAdminMenu', $event);

        return array_values((array) $event['items']);
    }

    public function isManager(): bool
    {
        $user = $this->grav['user'] ?? null;

        return $this->avatar() !== null && (bool) $user->authorize('site.w4os.manage');
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
     * Grav's HTTP client, for the engine and the blocks of the helpers.
     *
     * @return array{int, string, array<string, list<string>>} the status, the body, the headers
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $response = Client::getClient(['timeout' => 15])->request($method, $url, ['headers' => $headers] + ($body === null ? [] : ['body' => $body]));

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

    /** A value kept in the cache of Grav for the time of the setting "cache"; null is not kept. */
    private function cached(string $key, \Closure $value): mixed
    {
        $cache = $this->grav['cache'];
        $id = 'w4os-'.md5($key.'|'.$this->grav['language']->getActive());
        $kept = $cache->fetch($id);
        if ($kept !== false && $kept !== null) {
            return $kept;
        }
        $kept = $value();
        $kept === null || $cache->save($id, $kept, max(0, (int) ($this->config()['cache'] ?? 60)));

        return $kept;
    }
}
