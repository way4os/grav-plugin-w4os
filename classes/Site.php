<?php

namespace W4os\Grav;

use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Page\Page;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * The part that knows Grav: the settings and the API key, the engine and the blocks through Grav's HTTP client and cache, the pages
 * of the plugin, the avatar of the visitor.
 */
final class Site
{
    /** Where the API key typed on the settings page is kept, out of the configuration. */
    public const SECRET = 'user-data://w4os/api.json';

    private ?Engine $engine = null;

    public function __construct(private Grav $grav) {}

    /** @return array<string,mixed> the settings of the plugin */
    public function config(): array
    {
        return (array) $this->grav['config']->get('plugins.w4os', []);
    }

    public function engine(): Engine
    {
        $config = $this->config();
        $url = trim((string) ($config['api_url'] ?? '')) ?: (trim((string) ($config['url'] ?? '')) === '' ? '' : rtrim($config['url'], '/').'/api');

        return $this->engine ??= new Engine($url, $this->apiKey(), trim((string) ($config['grid'] ?? '')), $this->send(...));
    }

    public function forms(): Forms
    {
        return new Forms($this->engine(), $this->config(), fn (string $type) => $this->url($type));
    }

    public function blocks(): Blocks
    {
        return new Blocks((string) ($this->config()['url'] ?? ''), fn (string $url) => $this->cached($url, fn () => $this->get($url)));
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

    /** @return array<string,string> the route of each page of the plugin, by its type */
    public function routes(): array
    {
        $routes = array_map(fn ($route) => '/'.trim((string) $route, '/'), (array) ($this->config()['routes'] ?? []));
        if (! ($this->config()['register']['enabled'] ?? true)) {
            unset($routes['register'], $routes['confirm']);
        }

        return $routes;
    }

    /** The absolute address of a page of the plugin. */
    public function url(string $type): string
    {
        $route = $this->routes()[$type] ?? '/';

        return rtrim($this->grav['uri']->rootUrl(true), '/').$this->grav['language']->getLanguageURLPrefix().$route;
    }

    /**
     * The page of the plugin at the route asked for, unless the site has its own page there. The front matter is the one of
     * pages/<type>.md (its forms), title and text come from languages.yaml.
     */
    public function addPage(string $path): void
    {
        $type = array_search($path, $this->routes(), true);
        $pages = $this->grav['pages'];
        if ($type === false || $pages->find($path)) {
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

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /** The configuration (environment) wins, then the key kept in user-data. */
    private function apiKey(): string
    {
        $key = trim((string) ($this->config()['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $file = $this->grav['locator']->findResource(self::SECRET, true);
        $saved = $file && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return trim((string) ($saved['api_key'] ?? ''));
    }

    public function translate(string $key, array $vars = []): string
    {
        $text = (string) $this->grav['language']->translate([$key]);
        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Grav's HTTP client, for the engine.
     *
     * @return array{int, string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $response = Client::getClient(['timeout' => 15])->request($method, $url, ['headers' => $headers] + ($body === null ? [] : ['body' => $body]));

        return [$response->getStatusCode(), $response->getContent(false)];
    }

    private function get(string $url): ?string
    {
        try {
            [$status, $body] = $this->send('GET', $url, ['Accept' => 'text/html'], null);

            return $status === 200 ? $body : null;
        } catch (\Throwable $e) {
            $this->grav['log']->warning("w4os: $url: ".$e->getMessage());

            return null;
        }
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
