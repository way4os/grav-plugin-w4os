<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\Login\Events\UserLoginEvent;
use RocketTheme\Toolbox\Event\Event;
use W4OS\Grav\Blocks;
use W4OS\Grav\EngineException;
use W4OS\Grav\FormError;
use W4OS\Grav\Forms;
use W4OS\Grav\Items;
use W4OS\Grav\Lists;
use W4OS\Grav\Posts;
use W4OS\Grav\RememberTokens;
use W4OS\Grav\Site;

require_once __DIR__.'/classes/EngineException.php';
require_once __DIR__.'/classes/Engine.php';
require_once __DIR__.'/classes/FormError.php';
require_once __DIR__.'/classes/Forms.php';
require_once __DIR__.'/classes/Blocks.php';
require_once __DIR__.'/classes/Lists.php';
require_once __DIR__.'/classes/Items.php';
require_once __DIR__.'/classes/Bundled.php';
require_once __DIR__.'/classes/Site.php';
// The libraries of the plugin (the view models of w4os-views, the engine and the helpers when it bundles them)
is_file(__DIR__.'/vendor/autoload.php') && require_once __DIR__.'/vendor/autoload.php';

/**
 * The website of an OpenSimulator grid, as a thin bridge to the engine API: avatars register (with a model, confirming their
 * email), sign in with the Login plugin, manage their account, find a new password; the managers of the grid manage the avatars;
 * the pages show the blocks of the helpers.
 *
 *   Markdown (shortcode-core)   [grid-status]   [avatar-profile name="First.Last"]
 *   Twig                        w4os_block('grid-status')      w4os_block('profile', {name: 'First.Last'})
 */
class W4osPlugin extends Plugin
{
    private ?Site $site = null;

    /** The avatar signed in, read once per request (the account page). */
    private static ?array $current = null;

    /** The avatar of the profile page shown, once found. */
    private ?array $profiled = null;

    /** @var array<string,string> the blocks of the helpers the page could not read, and why */
    private array $blockFailures = [];

    /** The page of an earlier version laid out by the splash template of the plugin (now the theme's default page, onPageInitialized). */
    private $splash = null;

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the Login plugin signs a visitor in by "remember me" (from priority 10000)
            'onPluginsInitialized' => [['rememberTokens', 10001], ['onPluginsInitialized', 0]],
            'onUserLoginGuest' => ['onUserLoginGuest', 0],
            // Before the accounts of the site (priority 0), as the LDAP plugin of the Login plugin does
            'onUserLoginAuthenticate' => ['onUserLoginAuthenticate', 1000],
            // Before the Login plugin's own authorization (priority 0): a session back by its remember-me token
            'onUserLoginAuthorize' => ['onUserLoginAuthorize', 1000],
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    /** The "remember me" tokens of the Login plugin, with the requests a browser sends at once (RememberTokens). */
    public function rememberTokens(): void
    {
        if (! isset($this->grav['login']) || $this->isAdmin()) {
            return;
        }
        require_once __DIR__.'/classes/RememberTokens.php';
        $timeout = (int) $this->config->get('plugins.login.rememberme.timeout');
        $this->grav['login']->rememberMe()->setStorage(new RememberTokens('user-data://rememberme', $timeout));
    }

    /** A stolen "remember me" cookie: the browser lands on the sign-in page, told why, its cookie gone. */
    public function onUserLoginGuest(): void
    {
        $rememberMe = $this->grav['login']->rememberMe();
        $storage = $rememberMe->getStorage();
        if (! $storage instanceof RememberTokens || ! $storage->stolen()) {
            return;
        }
        $rememberMe->clearCookie(false);
        $this->grav['messages']->add($this->grav['language']->translate('PLUGIN_LOGIN.REMEMBER_ME_STOLEN_COOKIE'), 'error');
        $route = (string) $this->grav['login']->getRoute('login');
        if ($route !== '' && $this->grav['uri']->path() !== '/'.trim($route, '/')) {
            $this->grav->redirectLangSafe($route, 303);
        }
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }
        // The pictures of the grid (its web assets, under the helpers' address by default), served by the engine
        if ($answer = $this->site()->asset($this->grav['uri']->path())) {
            [$status, $body, $headers] = $answer;
            http_response_code($status);
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
            echo $body;
            exit;
        }
        // The helpers of the grid (viewers, simulators), when the site serves them
        if ($this->site()->helpers($this->grav['uri']->path())) {
            exit;
        }
        $this->enable([
            'onThemeInitialized' => ['onThemeInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigLoader' => ['onTwigLoader', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            // Last, once the others set their variables: the text of a page with blocks rendered before the theme shows its messages
            'onTwigSiteVariables' => [['onTwigSiteVariables', 0], ['onTwigSiteVariablesLast', -1000]],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            // Before the Login plugin, whose forgot-password route is the one of this plugin
            'onPagesInitialized' => ['onPagesInitialized', 10],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onFormInitialized' => ['onFormInitialized', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
            'onPageContentProcessed' => ['onPageContentProcessed', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * The messages given while the page rendered (the notices of the blocks of the helpers) at the marker of the plugin's pages, at
     * the start of their content (`<!-- w4os:messages -->`), the ones the theme showed before being gone.
     */
    public function onOutputGenerated(): void
    {
        $output = (string) $this->grav->output;
        // The sign-in page: "remember me" checked, the links to register and to a lost password under the form
        if ($this->grav['uri']->path() === $this->grav['config']->get('plugins.login.route', '/login')) {
            $output = Site::rememberChecked($output);
            ! str_contains($output, 'w4os-login-links') && ! ($this->grav['user']->authenticated ?? false)
                && $output = Site::withLoginLinks($output, $this->grav['twig']->processTemplate('w4os/login-links.html.twig'));
            $this->grav->output = $output;
        }
        // The blocks of the helpers the page could not show, said with the other messages
        $this->blockFailures === [] || $this->blockNotices();
        // The admin pages: the plugin's alerts; the others: the theme's messages, else the plugin's own
        $twig = $this->grav['twig'];
        foreach (['<!-- w4os:admin-messages -->' => 'w4os/admin-messages.html.twig', '<!-- w4os:messages -->' => null] as $marker => $template) {
            if (! str_contains($output, $marker)) {
                continue;
            }
            $template ??= $twig->twig()->getLoader()->exists('partials/messages.html.twig') ? 'partials/messages.html.twig' : 'w4os/messages.html.twig';
            $messages = $this->grav['messages']->all() ? $twig->processTemplate($template) : '';
            $this->grav->output = $output = str_replace($marker, $messages, $output);
        }
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
            if (in_array($e->key, Site::REFUSED, true)) {
                $event->setStatus(UserLoginEvent::AUTHENTICATION_FAILURE);
                $event->stopPropagation();
            }

            return;
        }
        $event->setUser($this->site()->user($avatar));
        $event->setStatus($event::AUTHENTICATION_SUCCESS);
        // The avatar lands on its profile, unless the sign-in was asked by a page it goes back to (Site::landing())
        $session = $this->grav['session'];
        $asked = $session->w4os_after_login ?? null;
        unset($session->w4os_after_login);
        $onLoginPage = $this->grav['uri']->path() === '/'.trim((string) $this->grav['config']->get('plugins.login.route', '/login'), '/');
        $event->setRedirect(Site::landing($asked, $onLoginPage, $session->redirect_after_login ?? null, $this->site()->profilePage((string) $avatar['name'])));
        $event->stopPropagation();
    }

    /**
     * A session that comes back by its remember-me token (the Login plugin, `remember_me_login`): the avatar as the engine has it
     * now, its level and its access with it (the admin pages, each action). A banned, disabled or deleted avatar is refused and its
     * tokens dropped; an engine that cannot be asked refuses this time and keeps them.
     */
    public function onUserLoginAuthorize(UserLoginEvent $event): void
    {
        $user = $event->getUser();
        $uuid = (string) (((array) $user->get('w4os', []))['uuid'] ?? '');
        if (! $event->getOption('remember_me_login') || $uuid === '') {
            return;
        }
        $gone = false;
        try {
            $avatar = $this->site()->forms()->avatar($uuid);
        } catch (FormError $e) {
            $avatar = null;
            $gone = $e->key === 'w4os.error_not_found';
        }
        if (! Site::resumes($avatar)) {
            if ($avatar !== null || $gone) {
                $remember = $this->grav['login']->rememberMe();
                $remember->clearCookie();
                $remember->getStorage()->cleanAllTriplets((string) $user->username);
            }
            $event->setStatus($event::AUTHORIZATION_DENIED);
            $event->stopPropagation();

            return;
        }
        // The user the Login plugin signed in, updated in place: it keeps its state (authenticated)
        $this->site()->user($avatar, $user);
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
        // A remote engine is reached at its own address only
        if ($object->get('engine', 'remote') !== 'bundled' && Site::apiUrl((array) $object->toArray()) === '') {
            $text = $this->site()->translate('w4os.admin_api_url_missing');
            isset($this->grav['admin']) ? $this->grav['admin']->setMessage($text, 'warning') : $this->grav['messages']->add($text, 'warning');
        }
    }

    /** Templates of the plugin come last, so a theme can override them. */
    /** The settings the plugin gives the active theme (the leftovers of a demo out), then the brand of the site, whatever the theme. */
    public function onThemeInitialized(): void
    {
        try {
            $this->site()->applyThemeFixes();
        } catch (\Throwable $e) {
            $this->grav['log']->warning('w4os: the settings of the theme not given: '.$e->getMessage());
        }
        $this->site()->applyBrand();
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__.'/templates';
    }

    /** The templates of the plugin also as @w4os, for a theme that extends one of them rather than copying it. */
    public function onTwigLoader(): void
    {
        $this->grav['twig']->addPath(__DIR__.'/templates', 'w4os');
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();
        $twig->addFunction(new \Twig\TwigFunction('w4os_block', fn (string $name, array $args = []) => $this->block($name, $args), ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('w4os_models', fn () => $this->site()->models()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar', fn () => self::current()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_list', fn (array $options) => $this->listing($options)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_item', fn () => $this->item()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_of', fn (string $uuid) => $this->avatarOf($uuid)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_count', fn (string $key, int $n) => $this->site()->count($key, $n)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_route', fn (string $type) => $this->site()->routes()[$type] ?? null));
        // A link of the site for the page shown: its base, the language of the visitor, never a prefix twice (the engine's Url::build())
        $twig->addFunction(new \Twig\TwigFunction('w4os_url', fn (?string $target, array $args = []) => $this->site()->links()->build((string) $target, $args)));
        // A link of the site by its kind (plain, more, button, primary: W4OS\Views\Link), its address built for the page shown
        $twig->addFunction(new \Twig\TwigFunction('w4os_link', fn (?string $target, string $text, string $kind = 'plain', array $args = [], string $class = '') => \W4OS\Views\Link::to($this->site()->links(), (string) $target, $text, $kind, $args, $class), ['is_safe' => ['html']]));
        // A link to an address built already (another grid, a viewer), by its kind (W4OS\Views\Link)
        $twig->addFunction(new \Twig\TwigFunction('w4os_anchor', fn (string $href, string $text, string $kind = 'plain', string $class = '') => \W4OS\Views\Link::html($href, $text, $kind, $class), ['is_safe' => ['html']]));
        // The visit of the grid from another one (w4os/hypergrid-visit.html.twig), null when it is not reachable by hypergrid
        $twig->addFunction(new \Twig\TwigFunction('w4os_hypergrid', fn () => $this->site()->hypergrid()));
        // A text a site gives a block (Markdown, inline HTML), as the text of a page, its links in the language of the visitor
        $twig->addFilter(new \Twig\TwigFilter('w4os_text', fn (?string $text, bool $more = false) => $this->site()->text($text, $more), ['is_safe' => ['html']]));
        // The grid added to a viewer (w4os/add-grid.html.twig) and the Connect block (w4os/getting-started.html.twig)
        $twig->addFunction(new \Twig\TwigFunction('w4os_add_grid', fn () => $this->site()->addGrid()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_login_uri', fn () => $this->site()->loginUri()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_grid_name', fn () => $this->site()->gridName()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_login_link', fn () => Site::loginLink($this->site()->loginUri())));
        // The news page of the site (/news), whose last posts the home page shows; null when the site has none
        $twig->addFunction(new \Twig\TwigFunction('w4os_home_news', fn () => $this->grav['pages']->find(Site::NEWS_ROUTE)));
        // Its steps from the avatar signed in: logged in-world once when it has a last visit, unknown when the engine does not say
        $twig->addFunction(new \Twig\TwigFunction('w4os_start_steps', function () {
            $avatar = self::current() ?? [];

            return Site::steps($avatar !== [], array_key_exists('lastSeen', $avatar) ? $avatar['lastSeen'] !== null : null);
        }));
        $twig->addFunction(new \Twig\TwigFunction('w4os_logout_route', fn () => Site::logoutRoute($this->site()->routes())));
        $twig->addFunction(new \Twig\TwigFunction('w4os_is_manager', fn () => $this->site()->isManager()));
        // The classes of the icon of the theme for an icon of the engine (setting icons)
        $twig->addFunction(new \Twig\TwigFunction('w4os_icon', fn (string $icon) => (string) ($this->config->get('plugins.w4os.icons.'.$icon) ?? '')));
        $twig->addFunction(new \Twig\TwigFunction('w4os_can', fn (string $type, string $what = 'change') => $this->site()->can($type, $what)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_language_names', fn () => $this->site()->languageNames()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_is_viewer', fn () => $this->site()->isViewer()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_page_args', fn () => $this->pageArgs()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_slogan', fn () => $this->site()->slogan()));
        // The fields of the settings of the site for the active theme, again before the form shows (settingsFields())
        $twig->addFunction(new \Twig\TwigFunction('w4os_settings_fields', fn ($form) => $form === null ? null : $this->settingsFields($form)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_admin_menu', fn () => $this->site()->adminMenu()));
        // The identity of the site for its admin pages (their layout, w4os-admin.html.twig)
        $twig->addFunction(new \Twig\TwigFunction('w4os_brand', fn () => $this->site()->brand()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_admin_css', fn (string $html) => Site::adminCss($html)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_health', fn () => $this->health()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_health_problems', fn () => $this->healthProblems()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_models_rule', fn () => $this->modelsRule()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_service', fn () => $this->service()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_area', function ($m2) {
            [$unit, $number] = Site::areaText((float) $m2, $this->language());

            return $this->site()->translate('w4os.'.$unit, [$number]);
        }));
        // A number in the language of the page (Intl)
        $twig->addFunction(new \Twig\TwigFunction('w4os_number', fn ($value, int $decimals = 2) => Site::number((float) $value, $this->language(), $decimals)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_create', fn (string $type) => $this->site()->createLink($type)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_shortcodes', fn () => Site::SHORTCODES));
        $twig->addFunction(new \Twig\TwigFunction('w4os_creation', fn () => $this->creation()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_edited', fn () => $this->edited()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_profile_page', fn (?string $name = null) => $this->site()->profilePage($name)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_name', fn (?string $uuid) => $this->avatarName($uuid)));
        $twig->addFilter(new \Twig\TwigFilter('w4os_date', fn (int|string|null $value, string $style = 'date') => \W4OS\Views\Dates::format($value, (string) ($this->grav['language']->getLanguage() ?: $this->grav['language']->getDefault() ?: 'en'), $style)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_age', fn (?int $created) => $this->age($created)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_dashboard', fn () => $this->dashboard()));
        // The view of a list (w4os-views): its actions only for a manager, from their level, never on their own row when it says so;
        // the column and the filter of a filter the page fixes (the grid of the site) left out
        $twig->addFunction(new \Twig\TwigFunction('w4os_list_view', fn (array $list, array $items, array $meta = [], array $query = [], array $links = [], array $fixed = []) => \W4OS\Views\ListView::of(
            $this->site()->isManager() ? $list : ['actions' => []] + $list, $items, $meta, $query, $links, $this->site()->avatar()['uuid'] ?? null, $this->site()->isManager() ? $this->site()->level() : null, $fixed)));
        // The view of the item of a page (w4os_item()): its header and its details (w4os-views ItemView); its actions for a manager
        $twig->addFunction(new \Twig\TwigFunction('w4os_item_view', fn (array $page) => \W4OS\Views\ItemView::of(
            $this->site()->openApi()->listOf((string) $page['type']) ?? ['columns' => []],
            (array) $page['item'],
            (array) ($page['groups'] ?? []),
            $this->site()->isManager() && is_array($page['form'] ?? null) ? \W4OS\Views\Rows::actions($page['form'], (array) $page['item'], $this->site()->avatar()['uuid'] ?? null, $this->site()->level()) : [],
        )));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_groups', fn (string $uuid) => array_column($this->avatarItems($uuid, 'groups'), 'name')));
        $twig->addFunction(new \Twig\TwigFunction('w4os_avatar_items', fn (string $uuid, string $kind) => $this->itemsHtml($uuid, $kind), ['is_safe' => ['html']]));
        // The stream of the profile shown, for its own avatar signed in on the site (not in a viewer): the form that writes a post,
        // the actions of its posts; and the profile of the helpers with them in its stream
        $twig->addFunction(new \Twig\TwigFunction('w4os_posts', fn () => $this->profiled !== null && ! $this->site()->isViewer() && ($this->profiled['uuid'] ?? null) === ($this->site()->avatar()['uuid'] ?? false) ? $this->ownPosts() : null));
        $twig->addFunction(new \Twig\TwigFunction('w4os_in_feed', fn (string $html, string $form, \W4OS\Views\PostsView $view) => Posts::inFeed($html, $form, fn (string $post) => $this->grav['twig']->processTemplate('w4os/post-actions.html.twig', ['view' => $view, 'post' => $post])), ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('w4os_profile_edit_url', fn () => $this->profiled === null ? null : $this->site()->profileEditUrl($this->profiled)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_logo', fn (string $name) => $this->site()->logo($name)));
        $twig->addFunction(new \Twig\TwigFunction('w4os_grid_logo', fn () => $this->site()->gridLogo()));
        $twig->addFunction(new \Twig\TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => $this->site()->picture($uuid, $default)));
        // A teleport link that fits where it is seen, browser or viewer (Site::tpLink(), W4OS\Views\TeleportLink)
        $twig->addFunction(new \Twig\TwigFunction('w4os_tp_link', fn (?string $destination, ?string $label = null, ?string $html = null, ?string $prefer = null) => $this->site()->tpLink((string) $destination, $label, $html, $prefer), ['is_safe' => ['html']]));
        // A picture of the grid that never shows broken: the picture when it has one, else the initials of its name (Site::pictureTag())
        $twig->addFunction(new \Twig\TwigFunction('w4os_picture_tag', fn (?string $uuid, ?string $default = null, ?string $name = null, string $class = '', int $size = 32) => Site::pictureTag(Site::hasPicture($uuid) ? $this->site()->picture($uuid, $default) : null, Site::initials($name), $class, $size), ['is_safe' => ['html']]));
    }

    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'] ?? null;
        $site = Site::siteStyles($page?->template(), $page ? ($page->header()->w4os ?? null) : null);
        if ($site) {
            $this->config->get('plugins.w4os.built_in_css', true) && $this->grav['assets']->addCss('plugin://w4os/css/w4os.css');
            // The teleport links' own style and script (TeleportLink::ASSETS, from the views), on the pages of the site: a link in a
            // text kept in a cache needs them too
            foreach (\W4OS\Views\TeleportLink::ASSETS['css'] as $file) {
                $this->config->get('plugins.w4os.built_in_css', true) && $this->grav['assets']->addCss('plugin://w4os/vendor/w4os/w4os-views/assets/'.$file);
            }
            foreach (\W4OS\Views\TeleportLink::ASSETS['js'] as $file) {
                $this->grav['assets']->addJs('plugin://w4os/vendor/w4os/w4os-views/assets/'.$file, ['group' => 'bottom', 'loading' => 'defer']);
            }
        }
        // The files of the blocks the text of a page shows (its shortcodes), before the theme writes its head
        if ($page !== null && ! $this->sitePage()) {
            foreach (Site::shortcodeBlocks(($page === $this->splash ? '[w4os-home]' : '').$page->rawMarkdown()) as $block) {
                $this->blockFiles($block);
            }
        }
        // The files of the page before the theme writes its head: a theme may write its assets before its content
        if ($this->sitePage()) {
            ($block = (string) ($page->header()->w4os_block ?? '')) === '' || $this->blockFiles($block);
            // The tabs of the Form plugin need jQuery (Grav's collection); js/form-tabs.js makes them keyboard tabs, keeps the tab
            // open in the address, and grows the text areas where the browser cannot
            if ((string) $page->header()->w4os === 'account') {
                $this->grav['assets']->add('jquery', 101);
                $this->grav['assets']->addJs('plugin://w4os/js/form-tabs.js', ['group' => 'bottom', 'loading' => 'defer']);
            }
            in_array((string) $page->header()->w4os, ['profile', 'account'], true) && $this->grav['assets']->addJs('plugin://w4os/js/keep-tab.js', ['group' => 'bottom', 'loading' => 'defer']);
        }
    }

    /**
     * One shortcode by block, by the names of w4os-wp (Site::BLOCK_SHORTCODES): [grid-info], [grid-status], [avatar-profile
     * name="First.Last"], [popular-places max=5 include-hypergrid], [web-search], [new-avatars]...; [login-uri] ([login-uri
     * add-grid] with the button that adds the grid to a viewer), [getting-started title="..." content="..." footer="..."], [hypergrid-visit title="..."], [tp-link destination="..." label="..."], [base_url], [grid-name], [site-slogan]. The
     * closing slash is free ([grid-info] or [grid-info /]).
     * [w4os-guest]...[/w4os-guest]: shown only to a visitor with no avatar signed in (the page has `cache_enable: false`)
     * [getting-started] shows the steps of the visitor: the page has `cache_enable: false` too
     * [w4os-register]...[/w4os-register]: shown only while registration is open (setting register.enabled)
     * [w4os-home]...[/w4os-home]: the home page of a grid around the text, its blocks beside it where there is room
     */
    public function onShortcodeHandlers(): void
    {
        $handlers = $this->grav['shortcode']->getHandlers();
        // One shortcode by block, by the names of w4os-wp ([grid-info], [popular-places max=5]); the login URI, the address of the site
        foreach (Site::BLOCK_SHORTCODES as $name => $block) {
            $handlers->add($name, fn ($shortcode) => $this->block($block, Blocks::shortcodeArgs((array) $shortcode->getParameters())));
        }
        // [login-uri]: the address alone, in a text; [login-uri add-grid]: with the button that adds the grid to a viewer
        $handlers->add('login-uri', fn ($shortcode) => array_key_exists('add-grid', (array) $shortcode->getParameters())
            ? $this->grav['twig']->processTemplate('w4os/add-grid.html.twig')
            : $this->grav['twig']->processTemplate('w4os/login-uri.html.twig', ['uri' => $this->site()->loginUri()]));
        // [getting-started title="..." content="..." footer="..."]: the Connect block, the next step to do standing out, the texts of
        // the site above and under its steps (templates/w4os/getting-started.html.twig)
        $handlers->add('getting-started', fn ($shortcode) => $this->grav['twig']->processTemplate('w4os/getting-started.html.twig', [
            'start_title' => trim((string) $shortcode->getParameter('title', '')),
            'start_content' => trim((string) $shortcode->getParameter('content', '')),
            'start_footer' => trim((string) $shortcode->getParameter('footer', '')),
        ]));
        // [tp-link destination="host:port:Region/x/y/z" label="..."]: a teleport link that fits where it is seen, browser or viewer
        $handlers->add('tp-link', fn ($shortcode) => $this->site()->tpLink((string) $shortcode->getParameter('destination', ''), ((string) $shortcode->getParameter('label', '')) ?: null));
        // [hypergrid-visit title="..." level=h2]: the visit of the grid for the avatars of other grids, nothing without hypergrid
        $handlers->add('hypergrid-visit', fn ($shortcode) => $this->grav['twig']->processTemplate('w4os/hypergrid-visit.html.twig', [
            'hg_title' => trim((string) $shortcode->getParameter('title', '')),
            'hg_level' => preg_match('/^h[2-6]$/', (string) $shortcode->getParameter('level', '')) === 1 ? (string) $shortcode->getParameter('level') : 'h2',
        ]));
        $handlers->add('base_url', fn () => htmlspecialchars(rtrim((string) $this->grav['uri']->rootUrl(true), '/')));
        $handlers->add('grid-name', fn () => htmlspecialchars($this->site()->gridName()));
        $handlers->add('site-slogan', fn () => htmlspecialchars($this->site()->slogan()));
        $handlers->add('w4os-guest', fn ($shortcode) => $this->site()->avatar() === null ? (string) $shortcode->getContent() : '');
        $handlers->add('w4os-register', fn ($shortcode) => isset($this->site()->routes()['register']) ? (string) $shortcode->getContent() : '');
        // [w4os-home]...[/w4os-home]: the home page of a grid around its text (templates/w4os/home.html.twig)
        $handlers->add('w4os-home', fn ($shortcode) => $this->grav['twig']->processTemplate('w4os/home.html.twig', ['content' => (string) $shortcode->getContent()]));
    }

    public function onPagesInitialized(): void
    {
        // An admin page at its address of before (under /account): its own now, for good (301), its query kept
        if (($moved = Site::movedRoute($this->grav['uri']->path(), $this->site()->routes())) !== null) {
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $this->grav->redirectLangSafe($moved.($query === '' ? '' : "?$query"), 301);
        }
        $this->site()->addMenu();
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
        // A page an earlier version laid out with the splash template of the plugin (w4os/splash, gone): the theme's default page,
        // the blocks of the home page around its text, as [w4os-home] does; nothing to change on an upgrade
        $page = $this->grav['page'] ?? null;
        if ($page !== null && Site::isSplash($page->template())) {
            $page->template('default');
            $this->splash = $page;
        }
        $path = $this->grav['uri']->path();
        $routes = $this->site()->routes();
        $type = array_search($path, $routes, true);
        if ($type === 'confirm' && ($token = (string) $this->grav['uri']->query('token')) !== '') {
            $this->confirm($token);
        } elseif (in_array($type, ['account', 'dashboard', 'shortcodes', 'settings', ...array_keys(Site::ADMIN_TYPES)], true) && $this->site()->avatar() === null) {
            // The page goes back there once signed in (Site::landing())
            $this->grav['session']->redirect_after_login = $this->grav['session']->w4os_after_login = $path;
            $this->grav->redirectLangSafe($this->grav['config']->get('plugins.login.route', '/login'));
        } elseif ($type === 'register' && $this->site()->avatar() !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            // The registration is a visitor's: an avatar signed in has its profile (and a visitor's profile is the registration)
            $this->grav->redirectLangSafe($routes['profile'] ?? $routes['account']);
        } elseif (isset(Site::ADMIN_OPERATIONS[$type]) && $this->site()->avatar() === null) {
            $this->grav['session']->redirect_after_login = $this->grav['session']->w4os_after_login = $path;
            $this->grav->redirectLangSafe($this->grav['config']->get('plugins.login.route', '/login'));
        } elseif (isset(Site::ADMIN_OPERATIONS[$type]) && ! $this->site()->canOperation($type)) {
            $this->grav->redirectLangSafe($this->site()->isManager() && isset($routes['dashboard']) ? $routes['dashboard'] : $routes['account']);
        } elseif (in_array($type, ['dashboard', 'shortcodes', ...array_keys(Site::ADMIN_TYPES)], true) && ! $this->site()->isManager()) {
            $this->grav->redirectLangSafe($routes['account']);
        } elseif ($type === 'settings' && ! $this->site()->canSettings()) {
            $this->grav->redirectLangSafe(isset($routes['dashboard']) && $this->site()->isManager() ? $routes['dashboard'] : $routes['account']);
        } elseif (isset(Site::ADMIN_TYPES[$type]) && ! $this->site()->can(Site::ADMIN_TYPES[$type], $type === 'edit' ? 'change' : 'read')) {
            // The admin pages open from the level of the setting; each one then from the level the contract gives it
            $this->grav->redirectLangSafe($type === 'edit' && isset($routes['manage']) ? $routes['manage'] : $routes['account']);
        } elseif ($path === Site::logoutRoute($routes)) {
            // Sign out, as the Login plugin does it (its task, with its nonce)
            $this->grav->redirectLangSafe(\Grav\Common\Uri::addNonce('/task'.$this->grav['config']->get('system.param_sep', ':').'login.logout', 'logout-form', 'logout-nonce'));
        } elseif (($page = $this->site()->pageAt($path))[0] === 'profile') {
            $this->profilePage($page[1]);
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'], $_POST['post']) && $this->postAction((array) $_POST);
        } elseif ($page[0] === 'search') {
            $this->grav['page']->title($this->site()->translate('w4os.search'));
        } elseif (Site::serviceGroup((string) $type) !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'run_task') {
            $this->runTask((string) ($_POST['task'] ?? ''), (string) ($_POST['nonce'] ?? ''));
        } elseif (in_array($type, ['manage', 'regions', 'region', 'simulators', 'simulator', 'flux', 'edit'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
            $this->listAction((array) ($_POST ?? []));
        } elseif ($type === 'edit') {
            // The name of the avatar in the title; the title of the list when there is no such avatar
            $edited = $this->edited();
            $this->grav['page']->title($edited === null ? $this->site()->translate('w4os.manage_title') : $this->site()->translate('w4os.edit_title', [(string) $edited['name']]));
        } elseif ($type === 'manage' && $this->grav['uri']->query('format') === 'avatars') {
            $this->avatarsFound((string) $this->grav['uri']->query('search'));
        } elseif ($type === 'health' && (string) $this->grav['uri']->query('check') !== '') {
            $this->healthLine((string) $this->grav['uri']->query('check'));
        } elseif ($type === 'models' && $this->grav['uri']->query('format') === 'preview') {
            $this->modelsPreview((array) ($_GET['filter'] ?? []));
        } elseif (in_array($type, ['region', 'simulator'], true)) {
            // The name of the item in the title
            $name = (string) ($this->item()['item']['name'] ?? '');
            $name === '' || $this->grav['page']->title($name);
        }
        // A page of the plugin in a viewer's panel: its own bare page, not the theme's (as the sitemap plugin gives its own)
        $this->sitePage() && $this->site()->isViewer() && $this->grav['twig']->template = 'w4os-viewer.html.twig';
    }

    /** Whether a page (the one shown by default) is one of the plugin's that shows in the site (pages/*.md), not an admin page. */
    private function sitePage($page = null): bool
    {
        $page ??= $this->grav['page'] ?? null;

        return $page !== null && isset($page->header()->w4os) && $page->template() !== 'w4os-admin';
    }

    /**
     * The content of a page of the plugin, in the default page of the theme (any theme has one): its part, rendered by the plugin
     * (w4os/page.html.twig), as shortcode-core fills a page with what it renders.
     */
    public function onPageContentProcessed(Event $event): void
    {
        $page = $event['page'];
        $this->sitePage($page) && $page === ($this->grav['page'] ?? null)
            && $page->setRawContent($this->grav['twig']->processTemplate('w4os/page.html.twig', ['page' => $page]));
        $page === $this->splash && $page->setRawContent($this->grav['twig']->processTemplate('w4os/home.html.twig', ['content' => (string) $page->getRawContent()]));
    }

    /** The account form shows what the engine has of the avatar signed in, the form of a manager the avatar it changes. */
    public function onFormInitialized(Event $event): void
    {
        $form = $event['form'];
        if ($form->getName() === 'w4os-item') {
            $this->itemForm($form);

            return;
        }
        if ($form->getName() === 'w4os-settings') {
            $this->settingsFields($form);

            return;
        }
        if ($form->getName() === 'w4os-new') {
            // The fields of a new avatar as the engine describes them (x-form of its creation), its button
            $creation = $this->creation();
            if ($creation['view'] !== null) {
                $this->viewForm($form, $creation['view']);
                $form->set('buttons', array_map(fn (array $button) => ['value' => 'w4os.'.$creation['form']['submit']] + $button, (array) $form->get('buttons')));
            }

            return;
        }
                if ($form->getName() === 'w4os-models') {
            // The fields of the rule as the engine describes them (x-form of the settings `models`), its values as the grid has them,
            // its button
            $rule = $this->modelsRule();
            if ($rule['view'] !== null) {
                $this->viewForm($form, $rule['view']);
                $form->set('buttons', array_map(fn (array $button) => ['value' => 'w4os.'.$rule['submit']] + $button, (array) $form->get('buttons')));
            }

            return;
        }
        if ($form->getName() === 'w4os-service') {
            // The settings of the service of the page as the engine describes them, what the options of the grid set shown, its button
            $service = $this->service();
            if ($service['view'] !== null) {
                // The address its path is under, as the engine says it, else the site or its helpers
                $site = rtrim((string) ($service['meta']['service']['base'] ?? '') ?: (string) $this->grav['uri']->rootUrl(true), '/');
                $bases = (string) ($service['meta']['service']['prefix'] ?? '')
                    ?: ['site' => $site, 'helpers' => rtrim((string) ($service['meta']['service']['helpers'] ?? '') ?: "$site/helpers", '/')];
                $this->viewForm($form, $service['view'], true, $bases, (array) ($service['meta']['fixed'] ?? []));
                $form->set('buttons', $service['changeable'] ? array_map(fn (array $button) => ['value' => 'w4os.'.$service['submit']] + $button, (array) $form->get('buttons')) : []);
            }

            return;
        }
        if ($form->getName() === 'w4os-post') {
            // The field the engine describes, its button
            if (($posts = $this->ownPosts()) !== null) {
                $form->setFields(Posts::fields($posts));
                $form->set('buttons', array_map(fn (array $button) => ['value' => 'w4os.'.$posts->submit] + $button, (array) $form->get('buttons')));
            }

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
        // The choices of the form (roles, wants, skills) as the engine gives them; without the fields the visitor's level may not
        // change (an avatar's role below a god)
        $described = $this->site()->openApi()->itemOf('avatars');
        // Nor the fields its grid does not allow (the web address of a profile with AllowUserProfileWebURLs false), as the contract
        // says (their `setting`, off in the settings of the item's grid)
        $described === null || $form->setFields(Items::without(
            Items::withOptions((array) $form->get('fields'), $described),
            [...($form->getName() === 'w4os-edit' ? \W4OS\Views\FormView::locked($described, ['manager'], $this->site()->level()) : []),
                ...Items::unallowed($described, (array) ($avatar['meta']['settings'] ?? []))],
        ));
        foreach (array_merge(['email', 'role'], Forms::PROFILE) as $key) {
            $form->getData()->get($key) === null && $form->setData($key, (string) ($avatar[$key] ?? ''));
        }
        foreach (Forms::PROFILE_LISTS as $key) {
            $form->getData()->get($key) === null && $form->setData($key, Forms::checked($avatar[$key] ?? []));
        }
        $form->getData()->get('published') === null && $form->setData('published', (bool) ($avatar['published'] ?? false));
        // The avatar a manager changes goes with the form: its address has no ?avatar= once posted
        $form->getName() === 'w4os-edit' && $form->setData('avatar', (string) $avatar['uuid']);
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
        $data = Items::posted($data, fn (string $key) => $this->grav['uri']->post("data.$key"));
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
                    // What the engine says it did, in the language of the page; the site's own text with an engine that says nothing
                    $said = $result['notice'] === null ? null : $site->notice($result['notice']);
                    if ($result['confirm']) {
                        $key = $result['link'] ? 'w4os.registered_test' : ($result['sent'] ? 'w4os.registered_confirm' : 'w4os.registered_not_sent');
                        $this->done($key, $routes['confirm'], [$name, $this->link($result['link'])], text: $result['link'] ? null : $said);
                    }
                    $this->done('w4os.registered', $login, [$name], text: $said);
                    // no break: done() redirects
                case 'resend':
                    // What the engine says it did, in the language of the page; the site's own text with an engine that says nothing
                    $resent = $forms->resendConfirmation((string) ($data['name'] ?? ''));
                    $resent['link']
                        ? $this->done('w4os.confirm_resent_test', $routes['confirm'], [$this->link($resent['link'])])
                        : $this->done('w4os.confirm_resent', $routes['confirm'], text: $resent['notice'] === null ? null : $site->notice($resent['notice']));
                    // no break
                case 'forgot':
                    $notice = $forms->forgot($data);
                    $forms->sends()
                        ? $this->done('w4os.forgot_sent', $routes['forgot'], text: $notice === null ? null : $site->notice($notice))
                        : $this->done('w4os.forgot_test', $routes['forgot']);
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
                    $this->site()->can('avatars') || throw new FormError('w4os.error_forbidden');
                    $edited = $this->edited() ?? throw new FormError('w4os.error_not_found');
                    // Never what the visitor's level may not change, whatever is posted
                    $described = $site->openApi()->itemOf('avatars');
                    $forms->edit($edited, $described === null ? $data : array_diff_key($data, array_flip(\W4OS\Views\FormView::locked($described, ['manager'], $site->level()))));
                    $site->forgetProfile((string) $edited['name']);
                    $this->done('w4os.manage_saved', $routes['manage'], [(string) $edited['name']]);
                    // no break
                case 'create':
                    // A new avatar a manager makes: what the form was given, active at once; its page next
                    $creation = $this->creation();
                    $creation['view'] !== null && $site->canOperation('new') || throw new FormError('w4os.error_forbidden');
                    $avatar = $site->forms()->create(array_diff_key($creation['view']->changes($data), ['id' => 1]));
                    $this->done('w4os.admin_avatar_created', isset($routes['edit']) ? $routes['edit'].'?'.http_build_query(['avatar' => (string) ($avatar['uuid'] ?? '')]) : $routes['manage'], [(string) ($avatar['name'] ?? '')]);
                    // no break
                case 'models':
                    // The rule of the avatar models, its fields the visitor changes only (FormView), written by the engine
                    $rule = $this->modelsRule();
                    $rule['changeable'] || throw new FormError('w4os.error_forbidden');
                    $changes = $rule['view']->changes($data);
                    if ($changes !== []) {
                        try {
                            $site->engine()->updateSettings('models', $changes);
                        } catch (EngineException $e) {
                            throw $forms->error($e);
                        }
                        $site->forgetModels();
                    }
                    $this->done('w4os.admin_models_saved', $routes['models']);
                    // no break
                case 'service':
                    // The settings of a service, the fields the visitor changes only (FormView), written by the engine
                    $service = $this->service();
                    $service['changeable'] || throw new FormError('w4os.error_forbidden');
                    $changes = Items::unfixed($service['view']->changes($data), (array) ($service['meta']['fixed'] ?? []));
                    if ($changes !== []) {
                        try {
                            $site->engine()->updateSettings((string) $service['group'], $changes);
                            $service['group'] === 'assets' && $site->forgetAssetsRoute();
                        } catch (EngineException $e) {
                            throw $forms->error($e);
                        }
                    }
                    $this->done('w4os.'.$service['saved'], $this->grav['uri']->path(), notes: $this->servicePageNotes((string) $service['group']));
                    // no break
                case 'settings':
                    $site->canSettings() || throw new FormError('w4os.error_forbidden');
                    $theme = (string) ($data['theme'] ?? '');
                    array_key_exists($theme, $this->grav['themes']->all()) || throw new FormError('w4os.error_invalid_request');
                    // The brand of the site, its own (the plugin's setting brand), the files uploaded with their original names; what
                    // the active theme kept of its own before is the site's now, out of the theme's configuration
                    $active = (string) $this->grav['config']->get('system.pages.theme');
                    $has = fn (string $field) => $site->themeField($field) !== null;
                    $fields = Site::brandFields($has);
                    foreach (array_keys(Site::BRAND_FOLDERS) as $setting) {
                        $files = (array) ($form->getData()->get($setting) ?? []);
                        // The theme's own file is no brand of the site: each theme keeps its own
                        isset($fields[$setting]) && Site::isDefault($files, $site->themeDefault($active, $fields[$setting])) && $files = [];
                        $files === [] ? $site->removeSetting('plugins/w4os', "brand.$setting") : $site->saveSetting('plugins/w4os', "brand.$setting", $files);
                    }
                    foreach ([...array_values($fields), ...array_keys(Site::brandSwitches($has))] as $field) {
                        $site->removeSetting("themes/$active", $field);
                    }
                    // Grav's own setting of the theme, as its admin writes it
                    $site->saveSetting('system', 'pages.theme', $theme);
                    $this->done('w4os.admin_site_saved', $routes['settings']);
                    // no break
                case 'post':
                    $posts = $this->ownPosts() ?? throw new FormError('w4os.error_forbidden');
                    try {
                        $site->engine()->call(...$this->postRequest($posts->write(trim((string) ($data['content'] ?? '')))));
                    } catch (EngineException $e) {
                        throw $forms->error($e);
                    }
                    $site->forgetProfile((string) $site->avatar()['name']);
                    // Written: the field empty again
                    $form->reset();
                    $this->done('w4os.'.\W4OS\Views\Done::key('post'), $site->profilePage());
                    // no break
                case 'item':
                    $page = $this->item();
                    $page['item'] === null && throw new FormError('w4os.error_not_found');
                    $changes = $page['view']->changes((array) ($this->grav['uri']->post('data') ?? []));
                    $id = \W4OS\Views\Rows::id($page['item']);
                    try {
                        $saved = $changes === [] ? [] : $site->engine()->call('PATCH', '/'.rawurlencode($page['type']).'/'.rawurlencode($id), ['type' => $page['type'], 'id' => $id, 'attributes' => $changes], [], ! empty($page['form']['scoped']));
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
     * The fields of the settings of the site for the active theme: the themes installed (Grav's list, their names), the one of the
     * site chosen; the brand of the site in the active theme's own fields (where it keeps the files, the ones it takes), none of
     * them for a theme without such a field. Set when the form is made and again before it shows: the Form plugin keeps a form it
     * made in its cache, and the active theme may have changed since.
     */
    private function settingsFields($form): void
    {
        // From the fields the page gives (a field left out for a theme comes back for another), the themes installed (Grav's list),
        // each by the name its blueprint gives, the one of the site chosen
        $fields = (array) ($form->getPage()->header()->form['fields'] ?? $form->get('fields'));
        $themes = [];
        $thumbnails = [];
        foreach ($this->grav['themes']->all() as $folder => $theme) {
            $themes[$folder] = (string) ($theme->blueprints()->get('name') ?: $folder);
            // The picture of the theme, as Grav gives it (its thumbnail.jpg, the address Themes::get() sets in its blueprint)
            $thumbnails[$folder] = (string) ($theme->blueprints()->get('thumbnail') ?: '');
        }
        $fields['appearance']['fields']['theme']['options'] = $themes;
        $fields['appearance']['fields']['theme']['thumbnails'] = $thumbnails;
        // The brand of the site, its own whatever the theme (applyBrand() gives it to the active one): its files in its data; a
        // notice under a field the active theme has no place for
        $supported = $this->site()->brandSupport();
        $notices = ['logo' => 'w4os.admin_site_logo_unsupported', 'favicon' => 'w4os.admin_site_favicon_unsupported'];
        foreach (Site::BRAND_FOLDERS as $setting => $folder) {
            $fields['appearance']['fields'][$setting]['destination'] = (string) $this->grav['locator']->findResource($folder, false, true);
            isset($supported[$setting]) || $fields['appearance']['fields'][$setting]['description'] = $notices[$setting];
        }
        $form->setFields($fields);
        // The values as the site has them now, whatever the form kept: its brand, else what the active theme kept of its own
        // before the brand was the site's (a site saved before; its first save makes it the site's)
        $active = (string) $this->grav['config']->get('system.pages.theme');
        $form->setData('theme', $active);
        $brand = (array) $this->grav['config']->get('plugins.w4os.brand', []);
        $fields = Site::brandFields(fn (string $field) => $this->site()->themeField($field) !== null);
        foreach (array_keys(Site::BRAND_FOLDERS) as $setting) {
            $files = (array) ($brand[$setting] ?? []);
            if ($files === [] && isset($fields[$setting])) {
                $own = (array) $this->grav['config']->get("themes.$active.{$fields[$setting]}", []);
                $files = Site::isDefault($own, $this->site()->themeDefault($active, $fields[$setting])) ? [] : $own;
            }
            $form->setData($setting, $files);
        }
    }

    /**
     * The form that makes an avatar, as the engine describes it (Contract::creationOf()), for the manager who may: its description
     * and its view (w4os-views FormView, an item that is not there yet).
     *
     * @return array{form: ?array<string,mixed>, view: ?\W4OS\Views\FormView}
     */
    private function creation(): array
    {
        static $creation = null;
        if ($creation !== null) {
            return $creation;
        }
        $form = $this->site()->canOperation('new') ? $this->site()->openApi()->creationOf('avatars') : null;

        return $creation = ['form' => $form, 'view' => $form === null ? null : \W4OS\Views\FormView::of($form, [], null, $this->site()->isManager(), $this->site()->level())];
    }

    /**
     * The rule of the avatar models of the grid (its settings `models`): its form as the engine describes it, for the visitor (what
     * the contract lets them change), its values, or why it could not be read.
     *
     * @return array{view: ?\W4OS\Views\FormView, changeable: bool, values: array<string,mixed>, error: ?string}
     */
    private function modelsRule(): array
    {
        return $this->settingsOf('models', 'models');
    }

    /**
     * The settings of the service of the page (a settings page of the admin: settings_economy...), as settingsOf() gives them; none
     * on another page.
     *
     * @return array{view: ?\W4OS\Views\FormView, changeable: bool, values: array<string,mixed>, error: ?string, group: ?string}
     */
    private function service(): array
    {
        $type = (string) $this->site()->pageAt($this->grav['uri']->path())[0];
        $group = Site::serviceGroup($type);

        if ($group === null) {
            return ['group' => null, 'view' => null, 'changeable' => false, 'values' => [], 'error' => null, 'help' => [], 'preview' => null, 'submit' => 'action_save', 'tasks' => [], 'run' => false];
        }
        $tasks = [];
        if (isset(Site::SERVICE_TASKS[$group])) {
            try {
                $tasks = Site::serviceTasks($this->site()->engine()->tasks(), $this->site()->engine()->gridNick(), $group);
            } catch (\Throwable) {
                $tasks = [];
            }
        }

        return ['group' => $group, 'tasks' => $tasks, 'run' => $tasks !== [] && $this->site()->canRunTask()] + $this->settingsOf($group, $type);
    }

    /**
     * A group of settings of the grid (the engine's settings of `group`): its form as the engine describes it, for the visitor (what
     * the contract lets them change), its values, or why it could not be read; none for whom the contract does not let read it (the
     * operation of the admin page `type`).
     *
     * @return array{view: ?\W4OS\Views\FormView, changeable: bool, values: array<string,mixed>, error: ?string}
     */
    private function settingsOf(string $group, string $type): array
    {
        static $read = [];
        if (isset($read[$group])) {
            return $read[$group];
        }
        $described = $this->site()->openApi()->settingsOf($group);
        $none = ['view' => null, 'changeable' => false, 'values' => [], 'error' => null, 'help' => [], 'preview' => null, 'submit' => 'action_save', 'saved' => 'settings_saved'];
        if ($described === null || ! $this->site()->canOperation($type)) {
            return $read[$group] = $none;
        }
        $none = ['help' => $described['description'], 'preview' => $described['preview'], 'submit' => $described['submit'], 'saved' => $described['saved'] ?? 'settings_saved'] + $none;
        try {
            ['values' => $values, 'meta' => $meta] = $this->site()->engine()->settingsPage($group);
            $view = \W4OS\Views\FormView::of($described, $values, null, $this->site()->isManager(), $this->site()->level());

            return $read[$group] = ['view' => $view, 'changeable' => $view->changeable, 'values' => $values, 'meta' => $meta] + $none;
        } catch (\Throwable $e) {
            return $read[$group] = ['error' => $e->getMessage()] + $none;
        }
    }

    /** Runs a task of the service of the page now (its button), for whom the contract lets; says what it said, back to the page. */
    private function runTask(string $id, string $nonce): void
    {
        $path = $this->grav['uri']->path();
        $tasks = array_column($this->service()['tasks'], null, 'id');
        if (! Utils::verifyNonce($nonce, 'w4os-task') || ! isset($tasks[$id]) || ! $this->site()->canRunTask()) {
            $this->grav['messages']->add($this->site()->translate('w4os.error_invalid_request'), 'error');
            $this->grav->redirectLangSafe($path, 303);
        }
        try {
            $task = $this->site()->engine()->runTask($id);
            $this->grav['messages']->add($this->site()->translate('w4os.admin_task_ran', [(string) ($tasks[$id]['label'] ?? $id), (string) ($task['message'] ?? '')]), ($task['status'] ?? '') === 'failed' ? 'error' : 'info');
        } catch (EngineException $e) {
            $this->grav['messages']->add($e->getMessage(), 'error');
        }
        $this->grav->redirectLangSafe($path, 303);
    }

    /** The language of the page (fr, pt-BR), for what is said or written in it. */
    private function language(): string
    {
        $language = $this->grav['language'];

        return (string) ($language->getLanguage() ?: ($language->getDefault() ?: 'en'));
    }

    /** A text of the language files with the values of its placeholders, as the health of the grid gives them. */
    private function said(string $key, array $params): string
    {
        return $this->site()->translate($key, $params);
    }

    /**
     * What a saved service says of the page of the site that handles it (the engine's meta.service: handledBy `site`, its block):
     * a warning when the site has no page at its path, or one without the block.
     *
     * @return list<string> the warnings, in the language of the visitor
     */
    private function servicePageNotes(string $group): array
    {
        try {
            ['values' => $values, 'meta' => $meta] = $this->site()->engine()->settingsPage($group);
        } catch (\Throwable) {
            return [];
        }
        $service = (array) ($meta['service'] ?? []);
        $block = (string) ($service['block'] ?? '');
        if (($service['handledBy'] ?? null) !== 'site' || $block === '') {
            return [];
        }
        $path = '/'.trim((string) ($values['path'] ?? ''), '/');
        [$type] = $this->site()->pageAt($path);
        $page = $this->grav['pages']->find($path);
        $holds = ($type !== false && Site::blockOfPage((string) $type) === str_replace('-', '_', $block))
            || ($page !== null && Site::holdsBlock((string) $page->rawMarkdown(), $block));

        return $holds ? [] : [$this->site()->translate($page === null && $type === false ? 'w4os.admin_service_no_page' : 'w4os.admin_service_no_block', [$path, $block])];
    }

    /**
     * The problems of the last health report of the grid, for the notice of the dashboard, with the address of the Health page;
     * none for whom the contract does not let read it, or when the report cannot be read (the Health page says why).
     *
     * @return array{count: int, labels: list<string>, url: string}
     */
    private function healthProblems(): array
    {
        $none = ['count' => 0, 'labels' => [], 'url' => ''];
        if (! $this->site()->canOperation('health')) {
            return $none;
        }
        try {
            return Site::healthProblems(Site::healthSaid($this->site()->engine()->health(), $this->said(...))) + ['url' => $this->site()->url('health')];
        } catch (\Throwable) {
            return $none;
        }
    }

    /**
     * The health of the grid and its addresses: checked again when the page asks it (`?refresh=1`), else the engine's last report;
     * why it could not tell, or could not check again (Site::healthReport()).
     *
     * @return array{health: ?array<string,mixed>, addresses: ?array<string,mixed>, error: ?string, refreshError: ?string}
     */
    private function health(): array
    {
        if (! $this->site()->canOperation('health')) {
            return ['health' => null, 'addresses' => null, 'error' => null, 'refreshError' => null];
        }
        $engine = $this->site()->engine();
        // Checked again when the page is asked so (its button), else the last report
        // Said in the language of the page (the texts of its lines)
        $report = Site::healthReport(fn (bool $refresh) => Site::healthSaid($engine->health($refresh), $this->said(...)), $this->grav['uri']->query('refresh') === '1');
        try {
            $addresses = $report['error'] === null ? $engine->addresses() : null;
        } catch (\Throwable) {
            $addresses = null;
        }

        return $report + ['addresses' => $addresses];
    }

    /** The stream of the avatar signed in, for itself (w4os-views PostsView): null when the engine describes none. */
    private function ownPosts(): ?\W4OS\Views\PostsView
    {
        $avatar = $this->site()->avatar();
        $posts = $avatar === null ? null : $this->site()->openApi()->postsOf();

        return $posts === null ? null : \W4OS\Views\PostsView::of($posts, (string) $avatar['uuid'], (string) $avatar['uuid'], $this->site()->level());
    }

    /**
     * The arguments of Engine::call() for a request of PostsView.
     *
     * @param array{string, string, array<string,mixed>, bool} $request
     * @return array{string, string, ?array<string,mixed>, array<string,scalar>, bool}
     */
    private function postRequest(array $request): array
    {
        [$method, $path, $data, $scoped] = $request;

        return [$method, $path, $method === 'DELETE' ? null : $data, [], $scoped];
    }

    /** An action of an avatar on a post of its own stream, from its profile page (a form with its nonce). */
    private function postAction(array $post): void
    {
        if (! Utils::verifyNonce((string) ($post['nonce'] ?? ''), 'w4os-post')) {
            $this->grav['messages']->add($this->site()->translate('w4os.error_nonce'), 'error');

            return;
        }
        $name = (string) $post['action'];
        try {
            $request = $this->ownPosts()?->request($name, (string) $post['post']) ?? throw new FormError('w4os.error_invalid_request');
            $this->site()->engine()->call(...$this->postRequest($request));
            $this->site()->forgetProfile((string) $this->site()->avatar()['name']);
            $this->done('w4os.'.\W4OS\Views\Done::key($name), $this->site()->profilePage());
        } catch (EngineException $e) {
            $this->grav['messages']->add($this->errorText($this->site()->forms()->error($e)), 'error');
        } catch (FormError $e) {
            $this->grav['messages']->add($this->errorText($e), 'error');
        }
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
            $row = $described === null ? [] : $this->site()->engine()->item($type, (string) ($post['id'] ?? ''), ! empty($described['scoped']));
            $action = array_column($described === null ? [] : \W4OS\Views\Rows::actions($described, $row, $this->site()->avatar()['uuid'] ?? null, $this->site()->level()), null, 'name')[$name] ?? null;
            $action === null && throw new FormError('w4os.error_invalid_request');
            // The page of the site that takes the token of a mail the action sends (`link`)
            [$method, $path, $data, $scoped] = \W4OS\Views\Rows::request($action, $row, isset($action['link']) ? $this->site()->forms()->tokenAttributes((string) $action['link']) : []);
            $this->site()->engine()->call($method, $path, $data, [], $scoped);
            $type === 'avatars' && $this->site()->forgetProfile((string) ($row['name'] ?? ''));
            $query = $item
                ? http_build_query([(string) ($options['param'] ?? 'id') => \W4OS\Views\Rows::id($row)])
                : http_build_query(Lists::query(Lists::showing($described, isset($options['filters']) ? (array) $options['filters'] : null), $_GET, 1)['page'] + array_intersect_key($_GET, ['page' => 1]));
            $this->done('w4os.'.\W4OS\Views\Done::key($name), $route.($query === '' ? '' : "?$query"), [(string) ($row['name'] ?? '')]);
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

        return '<a href="'.htmlspecialchars($this->site()->links()->build($route)).'" target="_blank">'.htmlspecialchars($route).'</a>';
    }

    /**
     * A message for the next page, and the redirect to it (the form is not sent twice by a reload).
     *
     * @param list<string|array<string,mixed>> $notes what is left to do, as the engine says it (its notices, shown after the message, as warnings)
     * @param ?string $text the message as the engine says it (a notice in the language of the page), instead of the one of the key
     */
    private function done(string $key, string $route, array $vars = [], array $notes = [], ?string $text = null): never
    {
        $this->grav['messages']->add($text === null ? $this->site()->translate($key, $vars) : htmlspecialchars($text), 'info');
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
            : sprintf(\W4OS\Engine\I18n\Catalog::plural($language, 'profile_age_days', $days), $days);
    }

    /**
     * The picks or the classifieds of an avatar as its profile page shows them: the same rendering, the helpers' (View::items()).
     */
    private function itemsHtml(string $uuid, string $kind): string
    {
        if (! class_exists(\W4OS\Helpers\View\View::class) || ! method_exists(\W4OS\Helpers\View\View::class, 'items')) {
            return '';
        }
        $templates = \Composer\InstalledVersions::getInstallPath('w4os/w4os-helpers').'/templates';
        $language = (string) ($this->grav['language']->getActive() ?: 'en');

        return (new \W4OS\Helpers\View\View($templates))->items($kind, $this->avatarItems($uuid, $kind), $this->site()->assetsUrl(), $language);
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
        $args += array_filter([
            'q' => $uri->query('q'),
            'topic' => $uri->query('topic'),
            'name' => $name === 'profile' ? $uri->query('name') : null,
            // The stream of a profile alone, when the page asks it (a viewer gets it so anyway, the helpers know it by its class)
            'feed_only' => $name === 'profile' ? $uri->query('feed_only') : null,
            // The category of the guide its cards open (?category=)
            'category' => $name === 'destination_guide' ? $uri->query('category') : null,
            // The class of the visitor, as the engine tells it (viewer or browser): the links of the block are the ones it takes (the
            // helpers see the site, not the visitor); one copy of a block per class is kept
            'client' => $this->site()->client(),
        ]);
        // A search: every parameter of its form the helpers list (Blocks::searchParameters()), as the page has them
        if (\W4OS\Grav\Blocks::name($name) === 'search') {
            foreach (\W4OS\Grav\Blocks::searchParameters() as $parameter) {
                $args[$parameter] ??= $uri->query($parameter);
            }
        }
        // The helpers translate their blocks: they are asked in the language of the page
        $language = $this->grav['language'];
        $args['lang'] = (string) ($language->getActive() ?: ($language->getDefault() ?: 'en'));
        // The pictures of the grid at the addresses of this site
        $args['assets'] = $this->site()->assetsUrl();
        // The links of the helpers to the pages of this site, in the language of the page
        $routes = $this->site()->routes();
        ($routes['search'] ?? '/') === '/' || $args['search_url'] = $this->site()->url('search');
        ($routes['profile'] ?? '/') === '/' || $args['web_profile_url'] = $this->site()->url('profile').'/[AGENT_NAME]';
        if ($name === 'profile' && empty($args['name']) && ($avatar = $this->site()->avatar())) {
            $args['name'] = str_replace(' ', '.', $avatar['name']);
        }

        try {
            $html = $this->site()->blocks()->html($name, $args);
        } catch (\W4OS\Grav\BlockUnavailable $e) {
            $this->blockUnavailable($name, $e->reason(fn (string $key, array $vars) => $this->site()->translate($key, $vars)), $e->getMessage());

            return '';
        }
        // The block that is the whole page (profile, guide, search) empty: the helpers gave nothing to show
        $html === '' && $this->pageBlock() === \W4OS\Grav\Blocks::name($name) && $this->blockUnavailable($name, $this->site()->translate('w4os.admin_blocks_nothing', []), 'empty answer');
        $html === '' || $this->blockFiles($name);

        return $html;
    }

    /** The block of the helpers that is the whole page (its header w4os_block: profile, guide, search), null for another page. */
    private function pageBlock(): ?string
    {
        $block = (string) ($this->grav['page']->header()->w4os_block ?? '');

        return $block === '' ? null : \W4OS\Grav\Blocks::name($block);
    }

    /**
     * The text of a page that shows blocks of the helpers (its shortcodes), rendered before the theme shows its messages (Grav
     * renders it when its template asks it, after them): the blocks it could not read are said with the messages of the page.
     */
    public function onTwigSiteVariablesLast(): void
    {
        $page = $this->grav['page'] ?? null;
        $page !== null && ! $this->sitePage() && Site::shortcodeBlocks(($page === $this->splash ? '[w4os-home]' : '').$page->rawMarkdown()) !== []
            && $page->content();
        $this->blockFailures === [] || $this->blockNotices();
    }

    /**
     * A block of the helpers that could not be read: logged (`logged`), and said on the page in its language (`reason`,
     * blockNotices()): before the theme shows its messages for the blocks of the text of the page, once the page is rendered for
     * the ones of its template.
     */
    private function blockUnavailable(string $name, string $reason, string $logged): void
    {
        $name = \W4OS\Grav\Blocks::name($name);
        if (! isset($this->blockFailures[$name])) {
            $this->grav['log']->warning("w4os: the block $name of the helpers cannot be read: $logged");
            $this->blockFailures[$name] = $reason;
        }
    }

    /**
     * What the page says of its blocks that could not be read, in the zone of messages: to a manager the reason and a link to the
     * health of the grid, to a visitor that the page is unavailable for now when the block is the whole page (Blocks::notices()).
     */
    private function blockNotices(): void
    {
        $site = $this->site();
        $manager = $site->isManager();
        $health = $manager && isset($site->routes()['health']) && $site->canOperation('health') ? $site->path('health', true) : null;
        foreach (\W4OS\Grav\Blocks::notices($this->blockFailures, $manager, $this->pageBlock(), $health, fn (string $key, array $vars) => $site->translate($key, $vars)) as $notice) {
            $this->grav['messages']->add($notice['text'], $notice['level']);
        }
        $this->blockFailures = [];
    }

    /** The styles and the script a block of the helpers needs, from the helpers the plugin bundles (View::blockFiles()). */
    private function blockFiles(string $name): void
    {
        \W4OS\Grav\Bundled::library();
        $view = \W4OS\Helpers\View\View::class;
        if (! class_exists($view) || ! method_exists($view, 'blockFiles')) {
            return;
        }
        $assets = $this->grav['assets'];
        $files = $view::blockFiles($name);
        foreach ($files['css'] as $file) {
            $assets->addCss('plugin://w4os/vendor/w4os/w4os-helpers/assets/'.$file);
        }
        foreach ($files['js'] as $file) {
            $assets->addJs('plugin://w4os/vendor/w4os/w4os-helpers/assets/'.$file, ['group' => 'bottom', 'loading' => 'defer']);
        }
        // The results of a search: a list with the detail of the item it selects, drawn by the views (ListHtml), their style and script
        if (\W4OS\Grav\Blocks::name($name) === 'search' && class_exists(\W4OS\Views\ListDetailView::class)) {
            foreach (\W4OS\Views\ListDetailView::ASSETS['css'] as $file) {
                $assets->addCss('plugin://w4os/vendor/w4os/w4os-views/assets/'.$file);
            }
            foreach (\W4OS\Views\ListDetailView::ASSETS['js'] as $file) {
                $assets->addJs('plugin://w4os/vendor/w4os/w4os-views/assets/'.$file, ['group' => 'bottom', 'loading' => 'defer']);
            }
        }
    }

    /**
     * A list of the engine for a page (`w4os_list` in its front matter: type, the filters it shows, the ones it always asks, the
     * rows of a page): the list as the engine describes it, a page of its rows from the search, filters, sort and page of the
     * address.
     *
     * @param array<string,mixed> $options type, filters, fixed, per_page
     * @return array{list: ?array<string,mixed>, items: list<array<string,mixed>>, meta: array<string,mixed>, query: array<string,string>, error: ?string}
     */
    private function listing(array $options, ?array $get = null): array
    {
        $type = (string) ($options['type'] ?? '');
        $list = $this->site()->openApi()->listOf($type);
        $error = ['list' => $list, 'items' => [], 'meta' => [], 'query' => [], 'error' => $this->site()->translate('w4os.error_channel_unavailable')];
        if ($list === null) {
            return $error;
        }
        $list = Lists::showing($list, isset($options['filters']) ? (array) $options['filters'] : null);
        $perPage = (int) ($options['per_page'] ?? $this->config->get('plugins.w4os.manage.per_page') ?: $list['pageSize'] ?: 50);
        // A list of the machine (its simulators) shows the grid of the site only, when it is filtered by grid
        $fixed = (array) ($options['fixed'] ?? []);
        $grid = $this->site()->engine()->grid();
        empty($list['scoped']) && $grid !== '' && in_array('grid', array_column($this->site()->openApi()->listOf($type)['filters'] ?? [], 'name'), true) && $fixed += ['grid' => $grid];
        $query = Lists::query($list, $get ?? $_GET, $perPage, $fixed);
        try {
            [$items, $meta] = $this->site()->engine()->items($type, $query['api'], ! empty($list['scoped']));

            return ['list' => Lists::offering($list, $meta, $query['page']), 'items' => $items, 'meta' => $meta, 'query' => $query['page'], 'fixed' => $fixed, 'error' => null];
        } catch (EngineException $e) {
            // What the engine refuses (a simulator it does not know...) said as it is, the rest as a channel down
            $refused = $e->status >= 400 && $e->status < 500 ? $this->errorText($this->site()->forms()->error($e)) : $error['error'];

            return ['list' => $list, 'query' => $query['page'], 'fixed' => $fixed, 'error' => $refused] + $error;
        }
    }

    /**
     * The cards of the dashboard, in the order of its page (w4os_cards): a block of the helpers (`block`, its arguments); how many of
     * a list (`page`) its filter keeps (`part`) of all it knows, as a text of the catalog (`text`: the regions online of the known
     * ones); the counts of a list in one card (`counts`: a filter each); the first rows of a list as it sorts them (`rows`, its
     * `title`, each leading to `item`). A list the visitor may not read gives no card.
     *
     * @return list<array<string,mixed>>
     */
    private function dashboard(): array
    {
        $cards = [];
        foreach ((array) ($this->grav['page']->header()->w4os_cards ?? []) as $card) {
            $card = (array) $card;
            if (isset($card['block'])) {
                $cards[] = ['kind' => 'block', 'block' => (string) $card['block'], 'title' => 'w4os.'.(string) ($card['title'] ?? 'dashboard_'.str_replace('-', '_', (string) $card['block'])),
                    'args' => array_diff_key($card, ['block' => 1, 'title' => 1])];
                continue;
            }
            $key = (string) ($card['page'] ?? '');
            $options = (array) ($this->site()->pageHeader($key)['w4os_list'] ?? []);
            $type = Site::ADMIN_TYPES[$key] ?? null;
            if ($options === [] || $type === null || ! $this->site()->can($type, 'read')) {
                continue;
            }
            $url = fn (array $query) => $this->site()->url($key).($query === [] ? '' : '?'.http_build_query($query));
            $count = function (array $filter) use ($options, $url): array {
                $data = $this->listing(['per_page' => 1] + $options, $filter);

                return ['list' => $data['list'], 'filter' => $data['query'], 'url' => $url($data['query']), 'error' => $data['error'],
                    'total' => $data['error'] === null ? (int) ($data['meta']['total'] ?? count($data['items'])) : null];
            };
            if (isset($card['counts'])) {
                $counts = array_map(fn ($filter) => $count((array) $filter), array_values((array) $card['counts']));
                $all = $count([]);
                $cards[] = ['kind' => 'counts', 'page' => $key, 'list' => $counts[0]['list'] ?? null, 'url' => $url([]), 'total' => $all['total'], 'counts' => $counts,
                    'error' => array_values(array_filter(array_column($counts, 'error')))[0] ?? null];
                continue;
            }
            if (isset($card['part'])) {
                $all = $count([]);
                $part = $count((array) $card['part']);
                $cards[] = ['kind' => 'count', 'page' => $key, 'list' => $all['list'], 'text' => 'w4os.'.(string) ($card['text'] ?? 'label_value'),
                    'part' => $part['total'], 'total' => $all['error'] === null && $part['error'] === null ? $all['total'] : null,
                    'url' => $url([]), 'partUrl' => $part['url'], 'error' => $all['error'] ?? $part['error']];
                continue;
            }
            $rows = max(1, (int) ($card['rows'] ?? 5));
            $data = $this->listing(['per_page' => $rows] + $options, []);
            $item = isset($card['item']) ? (string) $card['item'] : null;
            $cards[] = [
                'kind' => 'rows',
                'page' => $key,
                'title' => 'w4os.'.(string) ($card['title'] ?? $key.'_title'),
                'list' => $data['list'],
                'url' => $url([]),
                'items' => $data['items'],
                'item' => $item === null ? null : ['url' => $this->site()->url($item), 'param' => (string) ($this->site()->pageHeader($item)['w4os_item']['param'] ?? 'id')],
                'error' => $data['error'],
            ];
        }

        return $cards;
    }

    /**
     * The web profile of an avatar (/profile/First.Last): titled with its name, a 404 for a name that is no avatar's. The profile
     * page alone (/profile, my avatar in the menu) is the one of the avatar signed in; for a visitor, the way to make one
     * (registration, else the sign-in).
     */
    private function profilePage(string $name): void
    {
        $page = $this->grav['page'];
        if ($name === '') {
            $own = $this->site()->avatar();
            if ($own === null) {
                $routes = $this->site()->routes();
                $this->grav->redirectLangSafe($routes['register'] ?? $this->grav['config']->get('plugins.login.route', '/login'));
            }
            $name = str_replace(' ', '.', (string) $own['name']);
        }
        $error = null;
        try {
            $avatar = $name === '' ? null : $this->site()->forms()->avatar(str_replace('.', ' ', $name));
        } catch (FormError $e) {
            [$avatar, $error] = [null, $e];
        }
        $this->profiled = $avatar;
        // A grid that cannot be reached is no missing profile
        $avatar === null
            ? $page->modifyHeader('http_response_code', Site::profileStatus($error))
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
     * engine (Contract::itemOf()), the item, the roles of the visitor for it.
     *
     * @return array{type: string, list: ?string, form: ?array<string,mixed>, item: ?array<string,mixed>, roles: list<string>, error: ?string, lists: list<array<string,string>>, tab: ?string}
     */
    private function item(): array
    {
        static $page = null;
        if ($page === null) {
            $options = (array) ($this->grav['page']->header()->w4os_item ?? []);
            $type = (string) ($options['type'] ?? '');
            $page = ['type' => $type, 'list' => isset($options['list']) ? (string) $options['list'] : null, 'form' => $this->site()->openApi()->itemOf($type), 'item' => null, 'view' => null, 'roles' => [], 'error' => null, 'groups' => [], 'changeable' => false, 'edit' => false];
            $uri = $this->grav['uri'];
            // The lists of its own its page shows as tabs (the parcels of a region: lists, each its type, the filter that keeps it to
            // the item, its label), the one asked (?tab=)
            $lists = array_values(array_filter((array) ($options['lists'] ?? []), fn ($list) => is_array($list) && isset($list['type'], $list['filter'])));
            $tab = (string) $uri->query('tab');
            $page += ['lists' => $lists, 'tab' => in_array($tab, array_column($lists, 'type'), true) ? $tab : null];
            // Its UUID: in the address (?id=, or the parameter of the page: ?avatar=), posted by its form or by an action
            $param = (string) ($options['param'] ?? 'id');
            $id = trim((string) ($uri->post("data.$param") ?: $uri->post('id') ?: $uri->query($param)));
            try {
                $page['item'] = $id === '' || $page['form'] === null ? null : ($this->site()->engine()->item($type, $id, ! empty($page['form']['scoped'])) ?: null);
            } catch (EngineException $e) {
                $page['error'] = $e->status === 404 ? null : $this->errorText($this->site()->forms()->error($e));
            }
            if ($page['item'] !== null) {
                // Its form for the visitor (w4os-views FormView): who it is to the item, the fields it sees, the ones it changes (of its
                // level, the contract's)
                $view = \W4OS\Views\FormView::of($page['form'], $page['item'], $this->site()->avatar()['uuid'] ?? null, $this->site()->isManager(), $this->site()->level());
                $page['view'] = $view;
                $page['roles'] = $view->roles;
                $page['groups'] = $view->groups;
                $page['changeable'] = $view->changeable;
                // The page shows the item; its form (&edit=1, or posted) the fields the visitor changes
                $page['edit'] = $page['changeable'] && ($uri->query('edit') !== null || $uri->post('__form-name__') === 'w4os-item');
            }
        }

        return $page;
    }

    /**
     * A form of the site from the form of the viewer (w4os-views FormView): its fields, filled with the values of the item, and the
     * conditions its script shows or hides them by as it is changed (js/conditions.js).
     */
    private function viewForm($form, \W4OS\Views\FormView $view, bool $shown = false, string|array $base = '', array $fixed = []): void
    {
        $base = $base !== '' ? $base : rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        $fields = Items::fields($view, $shown, fn (string $key) => $this->site()->translate($key), $base, $fixed);
        $form->setFields($fields);
        foreach (Items::values($fields, $view->values) as $key => $value) {
            $form->getData()->get($key) === null && $form->setData($key, $value);
        }
        // What the options of the grid set, as it is (several addresses one by line)
        foreach ($shown ? $view->shown : [] as $field) {
            $value = $view->item[$field['name']] ?? '';
            $form->setData($field['name'], is_array($value) ? implode("\n", array_map('strval', $value)) : $value);
        }
        // The script of the form for its conditions, and for the parts a Use default hides
        $conditions = Items::conditions($view);
        ($conditions === [] && ! Items::defaulted($view)) || $form->set('attributes', ['data-w4os-conditions' => json_encode($conditions, JSON_UNESCAPED_SLASHES)] + (array) $form->get('attributes'));
    }

    /** The form of the page of an item: the engine's fields (x-form) as the visitor sees and changes them, filled with the item. */
    private function itemForm($form): void
    {
        $page = $this->item();
        if ($page['form'] === null || $page['item'] === null) {
            return;
        }
        $this->viewForm($form, $page['view']);
        // The button next to Save leads back to the page of the item
        $buttons = (array) $form->get('buttons');
        foreach ($buttons as $i => $button) {
            ($button['id'] ?? '') === 'w4os-view-item' && ($buttons[$i]['url'] = $this->grav['page']->url().'?'.http_build_query(['id' => \W4OS\Views\Rows::id($page['item'])]));
        }
        $form->set('buttons', $page['changeable'] ? $buttons : []);
    }

    /**
     * The avatars whose name has what a manager types (the avatar picker of the forms), as JSON: their UUID, name and picture; the
     * accounts of the services left out.
     */
    /**
     * The models a rule picks as it is changed, before it is saved (the preview of the form of the models, js/models-preview.js):
     * the filters the engine gives the preview (`match`, `pattern`, `list`), the others left out; its HTML, for whom reads the rule.
     *
     * @param array<string,mixed> $filters
     */
    private function modelsPreview(array $filters): never
    {
        $rule = $this->modelsRule();
        if ($rule['preview'] === null) {
            http_response_code(403);
            exit;
        }
        $asked = [];
        foreach ((array) $rule['preview']['filters'] as $name) {
            isset($filters[$name]) && is_scalar($filters[$name]) && $asked[$name] = mb_substr(trim((string) $filters[$name]), 0, 2000);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $this->grav['twig']->processTemplate('w4os/models-preview.html.twig', ['models' => $this->site()->models($asked), 'rule' => $rule]);
        exit;
    }

    /**
     * One line of the health checked again now (the engine's check of that line), as the page draws it, for whom reads the health
     * (js/health.js); why it could not be checked, as text, with a 502.
     */
    private function healthLine(string $id): never
    {
        $this->site()->canOperation('health') || (http_response_code(403) && exit);
        header('Cache-Control: no-store');
        try {
            $row = ['id' => $id] + Site::rowSaid($this->site()->engine()->healthCheck($id), $this->said(...));
        } catch (\Throwable $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->grav['twig']->processTemplate('w4os/health-row.html.twig', ['row' => $row]);
        exit;
    }

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
                $edited = $uuid !== '' && $this->site()->can('avatars') ? $this->site()->forms()->avatar($uuid) : null;
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
