<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\Login\Events\UserLoginEvent;
use RocketTheme\Toolbox\Event\Event;
use W4os\Grav\Blocks;
use W4os\Grav\EngineException;
use W4os\Grav\FormError;
use W4os\Grav\Forms;
use W4os\Grav\Site;

require_once __DIR__.'/classes/EngineException.php';
require_once __DIR__.'/classes/Engine.php';
require_once __DIR__.'/classes/FormError.php';
require_once __DIR__.'/classes/Forms.php';
require_once __DIR__.'/classes/Blocks.php';
require_once __DIR__.'/classes/Site.php';

/**
 * The website of an OpenSimulator grid, as a thin bridge to the engine API: avatars register (with a model, confirming their
 * email), sign in with the Login plugin, manage their account, find a new password; the managers of the grid manage the avatars;
 * the pages show the blocks of the helpers.
 *
 *   Markdown (shortcode-core)   [w4os block="grid-status" /]   [w4os block="profile" name="First.Last" /]
 *   Twig                        w4os_block('grid-status')      w4os_block('profile', {name: 'First.Last'})
 */
class W4osPlugin extends Plugin
{
    private ?Site $site = null;

    /** The avatar signed in, read once per request (the account page). */
    private static ?array $current = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Before the accounts of the site (priority 0), as the LDAP plugin of the Login plugin does
            'onUserLoginAuthenticate' => ['onUserLoginAuthenticate', 1000],
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            // Before the Login plugin, whose forgot-password route is the one of this plugin
            'onPagesInitialized' => ['onPagesInitialized', 10],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onFormInitialized' => ['onFormInitialized', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
        ]);
    }

    /**
     * The name (First Last) or the email of an avatar and its password, checked by the engine. A refusal lets the accounts of the
     * site try (an administrator of the site who is no avatar).
     */
    public function onUserLoginAuthenticate(UserLoginEvent $event): void
    {
        $credentials = $event->getCredentials();
        $login = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        if ($login === '' || $password === '' || ! $this->site()->engine()->ready()) {
            return;
        }
        try {
            $avatar = $this->site()->forms()->signIn($login, $password);
        } catch (FormError $e) {
            if ($e->key !== 'PLUGIN_W4OS.ERROR_SIGN_IN') {
                $this->grav['messages']->add($this->site()->translate($e->key), 'error');
                $this->grav['log']->info('w4os: sign-in of '.$login.': '.$e->getMessage());
            }

            return;
        }
        $event->setUser($this->site()->user($avatar));
        $event->setStatus($event::AUTHENTICATION_SUCCESS);
        $event->stopPropagation();
    }

    /** The API key typed on the settings page is kept out of the configuration (and of git), see Site::saveApiKey(). */
    public function onAdminSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        $file = is_object($object) && method_exists($object, 'file') ? $object->file() : null;
        if (! $file || ! str_ends_with(str_replace('\\', '/', (string) $file->filename()), '/plugins/w4os.yaml')) {
            return;
        }
        $key = trim((string) $object->get('api_key', ''));
        $key === '' || $this->site()->saveApiKey($key);
        $object->undef('api_key');
    }

    /** Templates of the plugin come last, so a theme can override them. */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__.'/templates';
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();
        $twig->addFunction(new \Twig\TwigFunction('w4os_block', fn (string $name, array $args = []) => $this->block($name, $args), ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('w4os_models', fn () => $this->site()->models()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar', fn () => self::current()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatars', fn () => $this->avatars()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_route', fn (string $type) => $this->site()->routes()[$type] ?? null));
        $twig->addFunction(new \Twig\TwigFunction('w4os_is_manager', fn () => $this->site()->isManager()));
    }

    public function onTwigSiteVariables(): void
    {
        $this->config->get('plugins.w4os.built_in_css', true) && $this->grav['assets']->addCss('plugin://w4os/css/w4os.css');
    }

    /** [w4os block="grid-status" /], [w4os block="profile" name="First.Last" /], [w4os block="search" /] */
    public function onShortcodeHandlers(): void
    {
        $this->grav['shortcode']->getHandlers()->add('w4os', fn ($shortcode) => $this->block(
            (string) $shortcode->getParameter('block', ''),
            array_filter(['name' => $shortcode->getParameter('name'), 'level' => $shortcode->getParameter('level'), 'title' => $shortcode->getParameter('title')]),
        ));
    }

    public function onPagesInitialized(): void
    {
        $this->site()->addPage($this->grav['uri']->path());
    }

    /**
     * What a page of the plugin does before it is shown: the link of a confirmation mail, the actions of the managers, the pages
     * that need an avatar signed in.
     */
    public function onPageInitialized(): void
    {
        $path = $this->grav['uri']->path();
        $routes = $this->site()->routes();
        $type = array_search($path, $routes, true);
        if ($type === 'confirm' && ($token = (string) $this->grav['uri']->query('token')) !== '') {
            $this->confirm($token);
        } elseif (in_array($type, ['account', 'manage'], true) && $this->site()->avatar() === null) {
            $this->grav['session']->redirect_after_login = $path;
            $this->grav->redirectLangSafe($this->grav['config']->get('plugins.login.route', '/login'));
        } elseif ($type === 'manage' && ! $this->site()->isManager()) {
            $this->grav->redirectLangSafe($routes['account']);
        } elseif ($type === 'manage' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->manage((array) ($_POST ?? []));
        }
    }

    /** The account form shows what the engine has of the avatar signed in. */
    public function onFormInitialized(Event $event): void
    {
        $form = $event['form'];
        if ($form->getName() !== 'w4os-profile' || ($avatar = self::current()) === null) {
            return;
        }
        foreach (array_merge(['email'], Forms::PROFILE) as $key) {
            $form->getData()->get($key) === null && $form->setData($key, (string) ($avatar[$key] ?? ''));
        }
    }

    /** The forms of the pages of the plugin: `process: [{w4os: register}]` in their front matter. */
    public function onFormProcessed(Event $event): void
    {
        if ($event['action'] !== 'w4os') {
            return;
        }
        $form = $event['form'];
        $data = $form->getData()->toArray();
        $site = $this->site();
        $forms = $site->forms();
        $routes = $site->routes();
        $login = $this->grav['config']->get('plugins.login.route', '/login');
        try {
            switch ((string) $event['params']) {
                case 'register':
                    if (($site->config()['register']['terms'] ?? '') !== '' && empty($data['terms'])) {
                        throw new FormError('PLUGIN_W4OS.ERROR_TERMS');
                    }
                    $result = $forms->register($data);
                    $name = (string) $result['avatar']['name'];
                    if ($result['confirm']) {
                        $this->done($result['sent'] ? 'PLUGIN_W4OS.REGISTERED_CONFIRM' : 'PLUGIN_W4OS.REGISTERED_NOT_SENT', $routes['confirm'], ['name' => $name]);
                    }
                    $this->done('PLUGIN_W4OS.REGISTERED', $login, ['name' => $name]);
                    // no break: done() redirects
                case 'resend':
                    $forms->resendConfirmation((string) ($data['name'] ?? ''));
                    $this->done('PLUGIN_W4OS.CONFIRM_RESENT', $routes['confirm']);
                    // no break
                case 'forgot':
                    $forms->forgot($data);
                    $this->done('PLUGIN_W4OS.FORGOT_SENT', $routes['forgot']);
                    // no break
                case 'reset':
                    $forms->reset($data);
                    $this->done('PLUGIN_W4OS.RESET_DONE', $login);
                    // no break
                case 'profile':
                    $this->requireAvatar();
                    $forms->profile(self::current() ?? throw new FormError('PLUGIN_W4OS.ERROR_UNAVAILABLE'), $data);
                    $this->done('PLUGIN_W4OS.PROFILE_SAVED', $routes['account']);
                    // no break
                case 'password':
                    $forms->password($this->requireAvatar(), $data);
                    $this->done('PLUGIN_W4OS.PASSWORD_SAVED', $routes['account']);
            }
        } catch (FormError $e) {
            $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->errorText($e)]));
            $event->stopPropagation();
        }
    }

    /** The link of a confirmation mail: the avatar is active, it can sign in. */
    private function confirm(string $token): void
    {
        try {
            $avatar = $this->site()->forms()->confirm($token);
            $this->done('PLUGIN_W4OS.CONFIRMED', $this->grav['config']->get('plugins.login.route', '/login'), ['name' => (string) ($avatar['name'] ?? '')]);
        } catch (FormError $e) {
            $this->grav['messages']->add($this->errorText($e), 'error');
        }
    }

    /** An action of a manager on an avatar (a form of the list, with its nonce). */
    private function manage(array $post): void
    {
        $route = $this->site()->routes()['manage'];
        if (! Utils::verifyNonce((string) ($post['nonce'] ?? ''), 'w4os-manage')) {
            $this->grav['messages']->add($this->site()->translate('PLUGIN_W4OS.ERROR_NONCE'), 'error');

            return;
        }
        $avatar = ['uuid' => (string) ($post['uuid'] ?? ''), 'name' => (string) ($post['name'] ?? '')];
        try {
            $this->site()->forms()->manage((string) ($post['action'] ?? ''), $avatar);
            $query = http_build_query(array_intersect_key($_GET, ['search' => 1, 'page' => 1]));
            $this->done('PLUGIN_W4OS.MANAGE_'.strtoupper((string) $post['action']), $route.($query === '' ? '' : "?$query"), ['name' => $avatar['name']]);
        } catch (FormError $e) {
            $this->grav['messages']->add($this->errorText($e), 'error');
        }
    }

    /** A message for the next page, and the redirect to it (the form is not sent twice by a reload). */
    private function done(string $key, string $route, array $vars = []): never
    {
        $this->grav['messages']->add($this->site()->translate($key, $vars), 'info');
        $this->grav->redirectLangSafe($route, 303);
        exit;
    }

    private function errorText(FormError $e): string
    {
        $text = $this->site()->translate($e->key);

        return $e->detail === '' ? $text : "$text ".$e->detail;
    }

    /** @return array{uuid: string, name: string, level: int} */
    private function requireAvatar(): array
    {
        return $this->site()->avatar() ?? throw new FormError('PLUGIN_W4OS.ERROR_FORBIDDEN');
    }

    /** The HTML of a block of the helpers; the search and the profile take their arguments from the address too. */
    private function block(string $name, array $args = []): string
    {
        $uri = $this->grav['uri'];
        $args += array_filter(['q' => $uri->query('q'), 'topic' => $uri->query('topic'), 'name' => $name === 'profile' ? $uri->query('name') : null]);
        if ($name === 'profile' && empty($args['name']) && ($avatar = $this->site()->avatar())) {
            $args['name'] = str_replace(' ', '.', $avatar['name']);
        }

        return $this->site()->blocks()->html($name, $args);
    }

    /**
     * The avatars for the list of the managers: search and page from the address.
     *
     * @return array{items: list<array<string,mixed>>, meta: array<string,mixed>, error: ?string}
     */
    private function avatars(): array
    {
        $uri = $this->grav['uri'];
        $query = ['page[size]' => 50, 'page[number]' => max(1, (int) $uri->query('page')), 'sort' => '-created', 'filter[technical]' => 'no'];
        ($search = trim((string) $uri->query('search'))) === '' || ($query['search'] = $search);
        try {
            [$items, $meta] = $this->site()->engine()->avatars($query);

            return ['items' => $items, 'meta' => $meta, 'error' => null];
        } catch (EngineException $e) {
            return ['items' => [], 'meta' => [], 'error' => $this->site()->translate('PLUGIN_W4OS.ERROR_UNAVAILABLE')];
        }
    }

    /**
     * The avatar signed in, as the engine has it now (the account page), once per request.
     *
     * @return ?array<string,mixed>
     */
    public static function current(): ?array
    {
        if (self::$current === null) {
            $site = new Site(\Grav\Common\Grav::instance());
            $avatar = $site->avatar();
            try {
                self::$current = $avatar === null ? [] : $site->forms()->avatar($avatar['uuid']);
            } catch (FormError) {
                self::$current = [];
            }
        }

        return self::$current ?: null;
    }

    /** The options of the model field of the registration form (data-options@ of pages/register.md): name => label. */
    public static function modelOptions(): array
    {
        $options = [];
        foreach ((new Site(\Grav\Common\Grav::instance()))->models() as $model) {
            $options[(string) $model['name']] = (string) ($model['label'] ?? $model['name']);
        }

        return $options;
    }

    /** The placeholder of the API key on the settings page: whether one is kept (data-placeholder@ of blueprints.yaml). */
    public static function adminKeyPlaceholder(): string
    {
        try {
            $site = new Site(\Grav\Common\Grav::instance());

            return $site->hasApiKey() ? $site->translate('PLUGIN_W4OS.ADMIN_API_KEY_SAVED') : 'osk_…';
        } catch (\Throwable) {
            return '';
        }
    }

    private function site(): Site
    {
        return $this->site ??= new Site($this->grav);
    }
}
