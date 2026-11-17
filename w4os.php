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
use W4os\Grav\Items;
use W4os\Grav\Lists;
use W4os\Grav\Site;

require_once __DIR__.'/classes/EngineException.php';
require_once __DIR__.'/classes/Engine.php';
require_once __DIR__.'/classes/FormError.php';
require_once __DIR__.'/classes/Forms.php';
require_once __DIR__.'/classes/Blocks.php';
require_once __DIR__.'/classes/OpenApi.php';
require_once __DIR__.'/classes/Lists.php';
require_once __DIR__.'/classes/Items.php';
require_once __DIR__.'/classes/Bundled.php';
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

    /** The avatar of the profile page shown, once found. */
    private ?array $profiled = null;

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
        // The helpers of the grid (viewers, simulators), when the site serves them
        if ($this->site()->helpers($this->grav['uri']->path())) {
            exit;
        }
        // The pictures of the grid, served by the bundled engine
        if ($answer = $this->site()->asset($this->grav['uri']->path())) {
            [$status, $body, $headers] = $answer;
            http_response_code($status);
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
            echo $body;
            exit;
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
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * The messages given while the page rendered (the notices of the blocks of the helpers) at the marker of the plugin's pages, at
     * the start of their content (`<!-- w4os:messages -->`), the ones the theme showed before being gone.
     */
    public function onOutputGenerated(): void
    {
        $marker = '<!-- w4os:messages -->';
        $output = (string) $this->grav->output;
        if (! str_contains($output, $marker)) {
            return;
        }
        // The theme's messages, else the plugin's own
        $twig = $this->grav['twig'];
        $template = $twig->twig()->getLoader()->exists('partials/messages.html.twig') ? 'partials/messages.html.twig' : 'w4os/messages.html.twig';
        $messages = $this->grav['messages']->all() ? $twig->processTemplate($template) : '';
        $this->grav->output = str_replace($marker, $messages, $output);
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
            // A wrong name or password: the accounts of the site try, and the Login plugin says it failed, without a reason
            if ($e->key === 'w4os.error_sign_in_refused') {
                return;
            }
            // One message: the failure, then its reason
            $event->setMessage(sprintf(
                '<strong>%s</strong><br>%s',
                htmlspecialchars($this->site()->translate('w4os.sign_in_failed')),
                htmlspecialchars($this->site()->translate($e->key)),
            ), 'error');
            $this->grav['log']->info('w4os: sign-in of '.$login.': '.$e->getMessage());
            // The avatar exists and is refused: no other account signs in with its name
            if (in_array($e->key, ['w4os.error_account_disabled', 'w4os.error_email_unconfirmed'], true)) {
                $event->setStatus(UserLoginEvent::AUTHENTICATION_FAILURE);
                $event->stopPropagation();
            }

            return;
        }
        $event->setUser($this->site()->user($avatar));
        $event->setStatus($event::AUTHENTICATION_SUCCESS);
        // The avatar lands on its profile, unless the sign-in was asked by a page it goes back to
        $this->grav['session']->redirect_after_login || $event->setRedirect($this->site()->profilePage((string) $avatar['name']));
        $event->stopPropagation();
    }

    /** The API key and the password of the database typed on the settings page are kept out of the configuration (and of git). */
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
        $password = (string) $object->get('bundled.database.password', '');
        $password === '' || $this->site()->saveDatabasePassword($password);
        $object->undef('bundled.database.password');
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
        $twig->addFunction(new \Twig\TwigFunction('w4os_list', fn (array $options) => $this->listing($options)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_monitor', fn () => $this->site()->monitor()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_item', fn () => $this->item()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_of', fn (string $uuid) => $this->avatarOf($uuid)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_count', fn (string $key, int $n) => $this->site()->count($key, $n)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_route', fn (string $type) => $this->site()->routes()[$type] ?? null));
        $twig->addFunction(new \Twig\TwigFunction('w4os_is_manager', fn () => $this->site()->isManager()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_language_names', fn () => $this->site()->languageNames()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_is_viewer', fn () => $this->site()->isViewer()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_page_args', fn () => $this->pageArgs()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_slogan', fn () => $this->site()->slogan()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_admin_menu', fn () => $this->site()->adminMenu()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_edited', fn () => $this->edited()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_profile_url', fn (string $name) => $this->site()->profileUrl($name)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_profile_page', fn (?string $name = null) => $this->site()->profilePage($name)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_name', fn (?string $uuid) => $this->avatarName($uuid)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_age', fn (?int $created) => $this->age($created)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_list_actions', fn (array $list, array $item) => Lists::actions($list, $item, $this->site()->avatar()['uuid'] ?? null)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_groups', fn (string $uuid) => array_column($this->avatarItems($uuid, 'groups'), 'name')));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_items', fn (string $uuid, string $kind) => $this->itemsHtml($uuid, $kind), ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('w4os_profile_edit_url', fn () => $this->profiled === null ? null : $this->site()->profileEditUrl($this->profiled)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_logo', fn (string $name) => $this->site()->logo($name)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_grid_logo', fn () => $this->site()->gridLogo()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => $this->site()->picture($uuid, $default)));
    }

    public function onTwigSiteVariables(): void
    {
        $this->config->get('plugins.w4os.built_in_css', true) && $this->grav['assets']->addCss('plugin://w4os/css/w4os.css');
    }

    /**
     * [w4os block="grid-status" /], [w4os block="profile" name="First.Last" /], [w4os block="search" /]
     * [w4os text="welcome_title" /]: a text of the language files, the name of the grid in its %s ("Welcome to %s")
     * [w4os info="uri" /]: the login URI of the grid (host:port), as text to copy
     * [w4os slogan /]: the slogan of the site (the theme's setting `slogan`), in the language of the page
     * [w4os-guest]...[/w4os-guest]: shown only to a visitor with no avatar signed in (the page has `cache_enable: false`)
     * [w4os-register]...[/w4os-register]: shown only while registration is open (setting register.enabled)
     */
    public function onShortcodeHandlers(): void
    {
        $handlers = $this->grav['shortcode']->getHandlers();
        $handlers->add('w4os', fn ($shortcode) => match (true) {
            ($text = (string) $shortcode->getParameter('text', '')) !== '' => htmlspecialchars($this->site()->translate('w4os.'.preg_replace('/[^a-z0-9_]/', '', $text), [$this->site()->gridName()])),
            $shortcode->getParameter('info') === 'uri' => '<code class="w4os-login-uri">'.htmlspecialchars($this->site()->loginUri()).'</code>',
            $shortcode->hasParameter('slogan') => htmlspecialchars($this->site()->slogan()),
            default => $this->block(
                (string) $shortcode->getParameter('block', ''),
                array_filter(['name' => $shortcode->getParameter('name'), 'level' => $shortcode->getParameter('level'), 'title' => $shortcode->getParameter('title')]),
            ),
        });
        $handlers->add('w4os-guest', fn ($shortcode) => $this->site()->avatar() === null ? (string) $shortcode->getContent() : '');
        $handlers->add('w4os-register', fn ($shortcode) => isset($this->site()->routes()['register']) ? (string) $shortcode->getContent() : '');
    }

    public function onPagesInitialized(): void
    {
        $this->site()->addPage($this->grav['uri']->path());
        $this->loginTexts();
    }

    /**
     * The title and the heading of the sign-in page of the Login plugin, from the catalog: the texts it translates them with
     * (its own page, before it adds it).
     */
    private function loginTexts(): void
    {
        $title = $this->site()->translate('w4os.login_title');
        $lang = $this->grav['language']->getLanguage() ?: 'en';
        foreach (['LOGIN_PAGE_TITLE', 'LOGIN_PAGE_HEADING'] as $key) {
            $this->grav['languages']->set("$lang.PLUGIN_LOGIN.$key", $title);
        }
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
        } elseif (in_array($type, ['account', 'dashboard', 'manage', 'edit', 'regions', 'region'], true) && $this->site()->avatar() === null) {
            $this->grav['session']->redirect_after_login = $path;
            $this->grav->redirectLangSafe($this->grav['config']->get('plugins.login.route', '/login'));
        } elseif (in_array($type, ['dashboard', 'manage', 'edit', 'regions', 'region'], true) && ! $this->site()->isManager()) {
            $this->grav->redirectLangSafe($routes['account']);
        } elseif (($page = $this->site()->pageAt($path))[0] === 'profile') {
            $this->profilePage($page[1]);
        } elseif ($page[0] === 'search') {
            $this->grav['page']->title($this->site()->translate('w4os.search'));
        } elseif ($type === 'edit') {
            // The name of the avatar in the title; the title of the list when there is no such avatar
            $edited = $this->edited();
            $this->grav['page']->title($edited === null ? $this->site()->translate('w4os.manage_title') : $this->site()->translate('w4os.edit_title', [(string) $edited['name']]));
        } elseif ($type === 'manage' && $this->grav['uri']->query('format') === 'avatars') {
            $this->avatarsFound((string) $this->grav['uri']->query('search'));
        } elseif (in_array($type, ['manage', 'regions', 'region'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
            $this->listAction((array) ($_POST ?? []));
        } elseif ($type === 'region') {
            // The name of the item in the title
            $name = (string) ($this->item()['item']['name'] ?? '');
            $name === '' || $this->grav['page']->title($name);
        }
    }

    /** The account form shows what the engine has of the avatar signed in, the form of a manager the avatar it changes. */
    public function onFormInitialized(Event $event): void
    {
        $form = $event['form'];
        if ($form->getName() === 'w4os-item') {
            $this->itemForm($form);

            return;
        }
        $avatar = match ($form->getName()) {
            'w4os-profile' => self::current(),
            'w4os-edit' => $this->edited(),
            default => null,
        };
        if ($avatar === null) {
            return;
        }
        // The choices of the form (roles, wants, skills) as the engine gives them
        ($described = $this->site()->openApi()->itemOf('avatars')) === null || $form->setFields(Items::withOptions((array) $form->get('fields'), $described));
        foreach (array_merge(['email', 'role'], Forms::PROFILE) as $key) {
            $form->getData()->get($key) === null && $form->setData($key, (string) ($avatar[$key] ?? ''));
        }
        foreach (Forms::PROFILE_LISTS as $key) {
            $form->getData()->get($key) === null && $form->setData($key, Forms::checked($avatar[$key] ?? []));
        }
        $form->getData()->get('published') === null && $form->setData('published', (bool) ($avatar['published'] ?? false));
        // The avatar a manager changes goes with the form: its address has no ?avatar= once posted
        $form->getName() === 'w4os-edit' && $form->setData('avatar', (string) $avatar['uuid']);
        // The button next to Save leads to the profile page of the avatar
        $buttons = (array) $form->get('buttons');
        foreach ($buttons as $i => $button) {
            ($button['id'] ?? '') === 'w4os-view-profile' && ($buttons[$i]['url'] = $this->site()->profilePage((string) $avatar['name']));
        }
        $form->set('buttons', $buttons);
    }

    /** The forms of the pages of the plugin: `process: [{w4os: register}]` in their front matter. */
    public function onFormProcessed(Event $event): void
    {
        if ($event['action'] !== 'w4os') {
            return;
        }
        $form = $event['form'];
        $data = $form->getData()->toArray();
        // The boxes checked, the email and "show in search", as posted: nothing is posted for a box not checked, and an empty email
        // is not kept in the data of the form (which keeps what it was filled with)
        if (in_array((string) $event['params'], ['profile', 'edit'], true)) {
            foreach (Forms::PROFILE_LISTS as $key) {
                $data[$key] = Forms::checked($this->grav['uri']->post("data.$key") ?? []);
            }
            $data['email'] = trim((string) ($this->grav['uri']->post('data.email') ?? ''));
            $data['published'] = (bool) $this->grav['uri']->post('data.published');
        }
        $site = $this->site();
        $forms = $site->forms();
        $routes = $site->routes();
        $login = $this->grav['config']->get('plugins.login.route', '/login');
        try {
            switch ((string) $event['params']) {
                case 'register':
                    ($minutes = $this->registrationsLimited()) === null || throw new FormError('w4os.error_too_many_registrations', vars: [$minutes]);
                    if (($site->config()['register']['terms'] ?? '') !== '' && empty($data['terms'])) {
                        throw new FormError('w4os.error_terms', vars: [$this->termsLink()]);
                    }
                    $result = $forms->register($data);
                    $name = (string) $result['avatar']['name'];
                    $site->forgetProfile($name);
                    if ($result['confirm']) {
                        $key = $result['link'] ? 'w4os.registered_test' : ($result['sent'] ? 'w4os.registered_confirm' : 'w4os.registered_not_sent');
                        $this->done($key, $routes['confirm'], [$name, $this->link($result['link'])]);
                    }
                    $this->done('w4os.registered', $login, [$name]);
                    // no break: done() redirects
                case 'resend':
                    $link = $forms->resendConfirmation((string) ($data['name'] ?? ''));
                    $this->done($link ? 'w4os.confirm_resent_test' : 'w4os.confirm_resent', $routes['confirm'], [$this->link($link)]);
                    // no break
                case 'forgot':
                    $forms->forgot($data);
                    $this->done($forms->sends() ? 'w4os.forgot_sent' : 'w4os.forgot_test', $routes['forgot']);
                    // no break
                case 'reset':
                    $forms->reset($data);
                    $this->done('w4os.reset_done', $login);
                    // no break
                case 'profile':
                    $avatar = $this->requireAvatar();
                    // A new password first: a wrong current one changes nothing
                    $password = (string) ($data['password'] ?? '') !== '';
                    $password && $forms->password($avatar, $data);
                    $result = $forms->profile(self::current() ?? throw new FormError('w4os.error_channel_unavailable'), $data);
                    $site->forgetProfile((string) $avatar['name']);
                    $key = $result['email'] !== null ? ($result['link'] ? 'w4os.email_pending_test' : 'w4os.email_pending') : ($password ? 'w4os.password_saved' : 'w4os.profile_saved');
                    $this->done($key, $routes['account'], [htmlspecialchars((string) $result['email']), $this->link($result['link'])]);
                    // no break
                case 'password':
                    $forms->password($this->requireAvatar(), $data);
                    $this->done('w4os.password_saved', $routes['account']);
                    // no break
                case 'edit':
                    $this->site()->isManager() || throw new FormError('w4os.error_unauthorized');
                    $edited = $this->edited() ?? throw new FormError('w4os.error_not_found');
                    $forms->edit($edited, $data);
                    $site->forgetProfile((string) $edited['name']);
                    $this->done('w4os.manage_saved', $routes['manage'], [(string) $edited['name']]);
                    // no break
                case 'item':
                    $page = $this->item();
                    $page['item'] === null && throw new FormError('w4os.error_not_found');
                    $changes = Items::changes($page['form'], $page['item'], (array) ($this->grav['uri']->post('data') ?? []), $page['roles']);
                    $id = Lists::id($page['item']);
                    try {
                        $saved = $changes === [] ? [] : $site->engine()->call('PATCH', '/'.rawurlencode($page['type']).'/'.rawurlencode($id), ['type' => $page['type'], 'id' => $id, 'attributes' => $changes]);
                    } catch (EngineException $e) {
                        throw $forms->error($e);
                    }
                    // Saved: the page of the item, as it is now, with what is left to do (the engine's reminders)
                    $this->done('w4os.manage_saved', $this->grav['page']->route().'?'.http_build_query(['id' => $id]), [(string) ($page['item']['name'] ?? '')], (array) ($saved['meta']['reminders'] ?? []));
            }
        } catch (FormError $e) {
            $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->errorText($e)]));
            $event->stopPropagation();
        }
    }

    /**
     * The registrations from the address of the visitor, counted the way the Login plugin counts its own (its limiter `registrations`,
     * settings `user_registration.max_attempts_count` and `max_attempts_interval`).
     *
     * @return ?int the minutes to wait when there were too many, else null
     */
    private function registrationsLimited(): ?int
    {
        $login = $this->grav['login'] ?? null;
        if ($login === null) {
            return null;
        }
        $limiter = $login->getRateLimiter('registrations');
        $ip = $login->getIpKey();
        $limiter->registerRateLimitedAction($ip, 'ip');

        return $limiter->isRateLimited($ip, 'ip') ? (int) $limiter->getInterval() : null;
    }

    /** The link of a confirmation mail: the new avatar is active, it can sign in; or the new email of an avatar is its email. */
    private function confirm(string $token): void
    {
        try {
            $avatar = $this->site()->forms()->confirm($token);
        } catch (FormError $e) {
            // The name of a registration taken meanwhile: the person registers again with another one
            $again = $e->key === 'w4os.error_name_taken' && isset($this->site()->routes()['register'])
                ? ' <a href="'.htmlspecialchars($this->site()->url('register')).'">'.htmlspecialchars($this->site()->translate('w4os.register_title')).'</a>'
                : '';
            $this->grav['messages']->add($this->errorText($e).$again, 'error');

            return;
        }
        $login = $this->grav['config']->get('plugins.login.route', '/login');
        if ($this->grav['uri']->query('change')) {
            $this->site()->avatar() === null || $this->grav['user']->set('email', (string) ($avatar['email'] ?? ''));
            $this->done('w4os.email_changed', $this->site()->avatar() === null ? $login : $this->site()->routes()['account'], [htmlspecialchars((string) ($avatar['email'] ?? ''))]);
        }
        $this->done('w4os.confirmed', $login, [(string) ($avatar['name'] ?? '')]);
    }

    /** A link of a message (the confirmation link of a test site, which mails nothing). */
    private function link(?string $url): string
    {
        return $url ? '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a>' : '';
    }

    /**
     * An action of a manager on a row of the list of a page, or on the item of an item page (a form with its nonce): the engine's
     * operation, checked against the row as the engine has it now.
     */
    private function listAction(array $post): void
    {
        $header = $this->grav['page']->header();
        $item = isset($header->w4os_item);
        $options = (array) ($item ? $header->w4os_item : ($header->w4os_list ?? []));
        $route = $this->grav['page']->route();
        if (! Utils::verifyNonce((string) ($post['nonce'] ?? ''), 'w4os-list')) {
            $this->grav['messages']->add($this->site()->translate('w4os.error_nonce'), 'error');

            return;
        }
        $type = (string) ($options['type'] ?? '');
        // The engine's description of the list, or of the item (the same actions)
        $described = $item ? $this->site()->openApi()->itemOf($type) : $this->site()->openApi()->listOf($type);
        $name = (string) ($post['action'] ?? '');
        try {
            $row = $described === null ? [] : $this->site()->engine()->item($type, (string) ($post['id'] ?? ''));
            $action = array_column($described === null ? [] : Lists::actions($described, $row, $this->site()->avatar()['uuid'] ?? null), null, 'name')[$name] ?? null;
            $action === null && throw new FormError('w4os.error_invalid_request');
            // The page of the site that takes the token of a mail the action sends (`link`)
            [$method, $path, $data] = Lists::request($action, $row, isset($action['link']) ? $this->site()->forms()->tokenAttributes((string) $action['link']) : []);
            $this->site()->engine()->call($method, $path, $data);
            $type === 'avatars' && $this->site()->forgetProfile((string) ($row['name'] ?? ''));
            $query = $item
                ? http_build_query(['id' => Lists::id($row)])
                : http_build_query(Lists::query(Lists::showing($described, isset($options['filters']) ? (array) $options['filters'] : null), $_GET, 1)['page'] + array_intersect_key($_GET, ['page' => 1]));
            $this->done('w4os.manage_'.$name, $route.($query === '' ? '' : "?$query"), [(string) ($row['name'] ?? '')]);
        } catch (EngineException $e) {
            $this->grav['messages']->add($this->errorText($this->site()->forms()->error($e)), 'error');
        } catch (FormError $e) {
            $this->grav['messages']->add($this->errorText($e), 'error');
        }
    }

    /** The link of the terms page of the registration. */
    private function termsLink(): string
    {
        $route = (string) ($this->site()->config()['register']['terms'] ?? '');

        return '<a href="'.htmlspecialchars($this->grav['base_url_relative'].$this->grav['language']->getLanguageURLPrefix().$route).'" target="_blank">'.htmlspecialchars($route).'</a>';
    }

    /**
     * A message for the next page, and the redirect to it (the form is not sent twice by a reload).
     *
     * @param list<string|array<string,mixed>> $notes what is left to do, as the engine says it (its notices, shown after the message, as warnings)
     */
    private function done(string $key, string $route, array $vars = [], array $notes = []): never
    {
        $this->grav['messages']->add($this->site()->translate($key, $vars), 'info');
        foreach ($notes as $note) {
            $this->grav['messages']->add(htmlspecialchars($this->site()->notice(is_array($note) ? $note : (string) $note)), 'warning');
        }
        $this->grav->redirectLangSafe($route, 303);
        exit;
    }

    /** The text of a refusal in the language of the visitor; what the engine said stays in the log, in English. */
    private function errorText(FormError $e): string
    {
        $e->detail === '' || $this->grav['log']->info("w4os: {$e->key}: {$e->detail}");

        return $this->site()->translate($e->key, $e->vars);
    }

    /** @return array{uuid: string, name: string, level: int} */
    private function requireAvatar(): array
    {
        return $this->site()->avatar() ?? throw new FormError('w4os.error_forbidden');
    }

    /**
     * The picks, the classifieds or the groups of an avatar, none when the engine cannot tell.
     *
     * @return list<array<string,mixed>>
     */
    private function avatarItems(string $uuid, string $kind): array
    {
        try {
            return in_array($kind, ['picks', 'classifieds', 'groups'], true) ? $this->site()->engine()->avatarItems($uuid, $kind) : [];
        } catch (EngineException $e) {
            $this->grav['log']->warning("w4os: the $kind of $uuid: ".$e->getMessage());

            return [];
        }
    }

    /** The age of an account as the viewer says it (joined today, else its days in the form of their number), '' without a date. */
    private function age(?int $created): string
    {
        if ((int) $created <= 0) {
            return '';
        }
        $days = intdiv(max(0, time() - (int) $created), 86400);
        $language = (string) ($this->grav['language']->getActive() ?: 'en');

        return $days === 0
            ? $this->site()->translate('w4os.profile_age_today')
            : sprintf(\OpenSim\I18n\Catalog::plural($language, 'profile_age_days', $days), $days);
    }

    /**
     * The picks or the classifieds of an avatar as its profile page shows them: the same rendering, the helpers' (View::items()).
     */
    private function itemsHtml(string $uuid, string $kind): string
    {
        if (! class_exists(\OpenSim\Helpers\View\View::class) || ! method_exists(\OpenSim\Helpers\View\View::class, 'items')) {
            return '';
        }
        $templates = \Composer\InstalledVersions::getInstallPath('magicoli/opensim-helpers').'/templates';
        $language = (string) ($this->grav['language']->getActive() ?: 'en');

        return (new \OpenSim\Helpers\View\View($templates))->items($kind, $this->avatarItems($uuid, $kind), $this->site()->engine()->assetsUrl(), $language);
    }

    /** The name of an avatar by its UUID (a partner), '' when the engine has none. */
    private function avatarName(?string $uuid): string
    {
        try {
            return trim((string) $uuid, '0-') === '' ? '' : (string) ($this->site()->forms()->avatar((string) $uuid)['name'] ?? '');
        } catch (FormError) {
            return '';
        }
    }

    /** The HTML of a block of the helpers; the search and the profile take their arguments from the address too. */
    private function block(string $name, array $args = []): string
    {
        $uri = $this->grav['uri'];
        $args += array_filter(['q' => $uri->query('q'), 'topic' => $uri->query('topic'), 'name' => $name === 'profile' ? $uri->query('name') : null]);
        // The helpers translate their blocks: they are asked in the language of the page
        $language = $this->grav['language'];
        $args['lang'] = (string) ($language->getActive() ?: ($language->getDefault() ?: 'en'));
        // The pictures of the grid at the addresses of this site
        $args['assets'] = $this->site()->engine()->assetsUrl();
        if ($name === 'profile' && empty($args['name']) && ($avatar = $this->site()->avatar())) {
            $args['name'] = str_replace(' ', '.', $avatar['name']);
        }

        return $this->site()->blocks()->html($name, $args);
    }

    /**
     * A list of the engine for a page (`w4os_list` in its front matter: type, the filters it shows, the ones it always asks, the
     * rows of a page): the list as the engine describes it, a page of its rows from the search, filters, sort and page of the
     * address.
     *
     * @param array<string,mixed> $options type, filters, fixed, per_page
     * @return array{list: ?array<string,mixed>, items: list<array<string,mixed>>, meta: array<string,mixed>, query: array<string,string>, error: ?string}
     */
    private function listing(array $options): array
    {
        $type = (string) ($options['type'] ?? '');
        $list = $this->site()->openApi()->listOf($type);
        $error = ['list' => $list, 'items' => [], 'meta' => [], 'query' => [], 'error' => $this->site()->translate('w4os.error_channel_unavailable')];
        if ($list === null) {
            return $error;
        }
        $list = Lists::showing($list, isset($options['filters']) ? (array) $options['filters'] : null);
        $perPage = (int) ($options['per_page'] ?? $this->config->get('plugins.w4os.manage.per_page') ?: $list['pageSize'] ?: 50);
        $query = Lists::query($list, $_GET, $perPage, (array) ($options['fixed'] ?? []));
        try {
            [$items, $meta] = $this->site()->engine()->items($type, $query['api']);

            return ['list' => Lists::offering($list, $meta, $query['page']), 'items' => $items, 'meta' => $meta, 'query' => $query['page'], 'error' => null];
        } catch (EngineException $e) {
            // What the engine refuses (a simulator it does not know...) said as it is, the rest as a channel down
            $refused = $e->status >= 400 && $e->status < 500 ? $this->errorText($this->site()->forms()->error($e)) : $error['error'];

            return ['list' => $list, 'query' => $query['page'], 'error' => $refused] + $error;
        }
    }

    /** The web profile of an avatar (/profile/First.Last): titled with its name, a 404 for a name that is no avatar's. */
    private function profilePage(string $name): void
    {
        $page = $this->grav['page'];
        try {
            $avatar = $name === '' ? null : $this->site()->forms()->avatar(str_replace('.', ' ', $name));
        } catch (FormError) {
            $avatar = null;
        }
        $this->profiled = $avatar;
        $avatar === null
            ? $page->modifyHeader('http_response_code', 404)
            : $page->title((string) $avatar['name']);
    }

    /**
     * The arguments of the block of a page of the grid the site serves: the name of a profile and the topic of a search from the path
     * (/profile/First.Last, /search/places), the rest from the query.
     *
     * @return array<string,string>
     */
    private function pageArgs(): array
    {
        [$type, $rest] = $this->site()->pageAt($this->grav['uri']->path());

        return match ($type) {
            'profile' => ['name' => $rest],
            'search' => $rest !== '' ? ['topic' => $rest] : [],
            default => [],
        };
    }

    /**
     * The item of the page of an item (?id=, or the id its form posts; w4os_item in its front matter): its description by the
     * engine (OpenApi::itemOf()), the item, the roles of the visitor for it.
     *
     * @return array{type: string, list: ?string, form: ?array<string,mixed>, item: ?array<string,mixed>, roles: list<string>, error: ?string}
     */
    private function item(): array
    {
        static $page = null;
        if ($page === null) {
            $options = (array) ($this->grav['page']->header()->w4os_item ?? []);
            $type = (string) ($options['type'] ?? '');
            $page = ['type' => $type, 'list' => isset($options['list']) ? (string) $options['list'] : null, 'form' => $this->site()->openApi()->itemOf($type), 'item' => null, 'roles' => [], 'error' => null, 'groups' => [], 'changeable' => false, 'edit' => false];
            $uri = $this->grav['uri'];
            $id = trim((string) ($uri->post('data.id') ?: $uri->post('id') ?: $uri->query('id')));
            try {
                $page['item'] = $id === '' || $page['form'] === null ? null : ($this->site()->engine()->item($type, $id) ?: null);
            } catch (EngineException $e) {
                $page['error'] = $e->status === 404 ? null : $this->errorText($this->site()->forms()->error($e));
            }
            if ($page['item'] !== null) {
                $page['roles'] = Items::roles($page['item'], $this->site()->avatar()['uuid'] ?? null, $this->site()->isManager());
                $page['groups'] = Items::groups($page['form'], $page['item'], $page['roles']);
                $page['changeable'] = Items::changeable($page['form'], $page['item'], $page['roles']);
                // The page shows the item; its form (&edit=1, or posted) the fields the visitor changes
                $page['edit'] = $page['changeable'] && ($uri->query('edit') !== null || $uri->post('__form-name__') === 'w4os-item');
            }
        }

        return $page;
    }

    /** The form of the page of an item: the engine's fields (x-form) as the visitor sees and changes them, filled with the item. */
    private function itemForm($form): void
    {
        $page = $this->item();
        if ($page['form'] === null || $page['item'] === null) {
            return;
        }
        $form->setFields(Items::fields($page['form'], $page['item'], $page['roles']));
        foreach (Items::values($page['form'], $page['item'], $page['roles']) as $key => $value) {
            $form->getData()->get($key) === null && $form->setData($key, $value);
        }
        // The button next to Save leads back to the page of the item
        $buttons = (array) $form->get('buttons');
        foreach ($buttons as $i => $button) {
            ($button['id'] ?? '') === 'w4os-view-item' && ($buttons[$i]['url'] = $this->grav['page']->url().'?'.http_build_query(['id' => Lists::id($page['item'])]));
        }
        $form->set('buttons', $page['changeable'] ? $buttons : []);
    }

    /**
     * The avatars whose name has what a manager types (the avatar picker of the forms), as JSON: their UUID, name and picture; the
     * accounts of the services left out.
     */
    private function avatarsFound(string $search): never
    {
        $found = [];
        if (mb_strlen(trim($search)) >= 2) {
            try {
                [$items] = $this->site()->engine()->items('avatars', ['search' => trim($search), 'filter[technical]' => 'no', 'sort' => 'name', 'page[size]' => 20]);
                foreach ($items as $avatar) {
                    $found[] = ['value' => (string) ($avatar['uuid'] ?? ''), 'text' => (string) ($avatar['name'] ?? ''), 'picture' => $this->site()->picture($avatar['profileImage'] ?? null, 'person')];
                }
            } catch (EngineException) {
                $found = [];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($found, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @return ?array<string,mixed> an avatar of the grid by its UUID (its name and picture for a field), null when none */
    private function avatarOf(string $uuid): ?array
    {
        static $known = [];
        if (! array_key_exists($uuid, $known)) {
            try {
                $known[$uuid] = $uuid === '' ? null : ($this->site()->engine()->item('avatars', $uuid) ?: null);
            } catch (EngineException) {
                $known[$uuid] = null;
            }
        }

        return $known[$uuid];
    }

    /** The avatar a manager changes (the UUID the edit form posts, else ?avatar=<UUID> of the page), as the engine has it, once per request. */
    private function edited(): ?array
    {
        static $edited = false;
        if ($edited === false) {
            $uri = $this->grav['uri'];
            $uuid = trim((string) ($uri->post('data.avatar') ?: $uri->query('avatar')));
            try {
                $edited = $uuid !== '' && $this->site()->isManager() ? $this->site()->forms()->avatar($uuid) : null;
            } catch (FormError) {
                $edited = null;
            }
        }

        return $edited;
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

            return $site->hasApiKey() ? $site->translate('w4os.admin_api_key_saved') : 'osk_…';
        } catch (\Throwable) {
            return '';
        }
    }

    private function site(): Site
    {
        return $this->site ??= new Site($this->grav);
    }
}
