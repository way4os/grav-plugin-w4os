# Developers

## The rule

The engine API provides everything; the plugin only consumes it. An operation that is missing (a check, a field, a mail) goes into the engine (`w4os-engine`, `src/Api/`), never here. The rules of the names, the passwords and the models are the engine's: the plugin checks only what its forms add (the password typed twice, the terms).

w4os (`~/Projects/w4os-wp`, branch `speculoos`) is the reference of the method: the registration form, the models picker, the profile and account pages, the sign-in with the avatar name or the email are all done there. Follow it.

## Two projects

The plugin and the site are two projects:

- **This plugin** is reusable on any Grav site: settings, the pages of the accounts and of the managers, the blocks. It holds no content of the site and imposes no theme (its style sheet can be switched off).
- **The light w4os site** is a project of its own, with its own apt package and installer: Grav, this plugin as a dependency, a light theme, the default `.md` pages, and the installer that sets the address and the key of the API. What belongs to a site (pages, theme, Grav config) goes there.

## The engine and the helpers in the plugin

The plugin requires the projects of the OpenSim family it uses (`w4os/w4os-engine`, `w4os/w4os-helpers`, `magicoli/opensim-rest-php`): in development they are the folders next to this one (path repositories, `@dev`, linked), the way the other projects of the family do (`vendor/bin/build-tools switch dev|release`). The release zip carries them as files with the rest of `vendor`: a site needs nothing else. `vendor` is loaded only when the settings ask the bundled engine (`Site::bundled()`), after Grav's own autoloader (`prepend-autoloader: false`), and composer's platform check is off: an extension the engine needs (pdo_mysql, imagick) never stops a site that uses the API of its grid.

```bash
composer install                       # with the family next to this folder (../opensim-engine...)
vendor/bin/build-tools build zip       # dist/grav-plugin-w4os-<version>.zip, from the last commit (.distignore, .version)
```

The site serves the helpers of the grid (`serve_helpers`) under the prefix of its setting `url` only, and only when the grid sets one of its helpers there (`Bundled::answers()`: the addresses of `Grid::addresses(true)` whose source is not the engine's default); those are answered by the helpers' Slim application (`W4OS\Helpers\App::create()->run()`, in `onPluginsInitialized`), every other one by Grav, never a route outside the prefix. The helpers take off the path of their address themselves (`GridMiddleware`). They serve the grid the plugin gives them (`Bundled::grid()`): its environment is built from the site's settings (`Bundled::environment()`: the config folder of the grids or the described grid, the site's own folders, the access to its databases through the engine's `DatabaseAccess`), never `Layout::load()` nor the install of the server.

`classes/Bundled.php` uses them as libraries: the engine's `Api\Router` answers the calls of `Engine` (no HTTP, no key), the helpers' `View` makes the blocks, and the pictures of the models are served at the route `assets`. Its grid is the one of an OpenSim install of the server (`Layout` of its `w4os.conf`), or a grid described by its login address and its Robust database: the plugin writes, in `user-data://w4os/opensim/etc/grids/<nick>/`, a remote grid `<nick>.conf` and a `Helpers.ini` with its database and its grid info (the file the helpers read on a web host without OpenSimulator).

## Layout

- `w4os.php`: the Grav plugin, no logic of its own: the events (sign-in, pages, forms, Twig, shortcode, settings).
- `classes/Engine.php`: the engine API (JSON:API, problems as `EngineException`, Bearer key). `Forms.php`: what the forms ask of the engine, refusals as `FormError` (the key of their text). `Blocks.php`: the blocks of the helpers. Plain PHP, no Grav symbol, tested with a fake transport (`tests/Support.php`).
- `classes/Site.php`: the part that knows Grav: settings and API key, Grav's HTTP client and cache, the pages of the plugin, the avatar as a Grav user.
- `pages/*.md`: the front matter of the pages of the plugin, with their forms (Form plugin, `process: [{w4os: register}]`); title and texts come from the language files (`languages/<lang>.yaml`).
- The pages of the plugin need no theme of their own: `pages/*.md` take the theme's `default` template, whose content the plugin fills with `templates/w4os/page.html.twig` (`onPageContentProcessed`, as shortcode-core fills a page), their files added before the theme writes its head (`onTwigSiteVariables`); in a viewer, `templates/w4os-viewer.html.twig` replaces the theme (`$grav['twig']->template`, as the sitemap plugin does). No template of the plugin extends `partials/base.html.twig` or names the blocks of a theme. The menu of the site is pages of Grav's tree (`Site::menuEntries()`, `addMenu()`), with their children: every theme draws them the same way; `templates/w4os/blocks/grid-status.html.twig`: the status of the grid from the data of its block (`?format=json`), in the language of the site (`classes/Format.php`, intl). `templates/forms/fields/w4os_models`: the models picker. A theme overrides any of them.
- `templates/w4os-admin.html.twig`: the layout of the admin pages (dashboard, simulators, regions, avatars), the plugin's own on Tabler, never the theme's: the identity of the site in it (`Site::brand()`: its title, its logo, its accent color as `--tblr-primary`), one sidebar of the sections the visitor opens (`Site::adminMenu()`), the visitor's light or dark mode; `css/admin.css` its few additions.
- `classes/Assets.php`: the libraries of the browser (below).
- `dev/`: a local development Grav (`dev/grav.sh`, its config in `dev/site`); the pages of a site belong to the site (the w4os site has the default ones), not to the plugin.

## The libraries of the browser

Tabler (`@tabler/core`), Tabler Icons (`@tabler/icons-webfont`) and Tom Select (`tom-select`) are npm releases pinned in `composer.json` as `package` repositories (name `npm-asset/<name>`, its version, the URL of its tarball on registry.npmjs.org and its sha1), required at that version: `composer install` puts them in `vendor/npm-asset/`, and `Assets::prune()` (post-install-cmd, post-update-cmd) keeps of each only what the plugin serves (`Assets::KEEP`) and its license, so the release zip carries them light. To update one, take a release at least two weeks old, then in its `package` entry change the version, the URL and the shasum, which npm gives:

```bash
curl -s https://registry.npmjs.org/@tabler/core/1.7.3 | python3 -c "import json,sys; d=json.load(sys.stdin)['dist']; print(d['tarball'], d['shasum'])"
composer update npm-asset/tabler-core       # then commit composer.json and composer.lock
```

A file of a new version the plugin serves goes in `Assets::KEEP` (and `tests/AssetsTest.php`).

## Dates

A date is told by `Site::date()` (Twig filter `w4os_date`, its style): absolute dates by PHP's `IntlDateFormatter` in the language of the page; a recent time in a list (`Site::RECENT`, 7 days) relatively by Carbon (`nesbot/carbon`, `diffForHumans()`: "il y a 15 heures", "15 hours ago", in the right order and case for each language, which Grav's `nicetime` cannot). Carbon is pinned in `composer.json`; its translations are pruned to the languages of the plugin (`languages/*.yaml`) by `Assets::pruneLocales()` in the same post-install step. Its `symfony/translation` and `symfony/clock` are required at `^7.4`, the versions that run on PHP 8.3 (the 8.x ones need PHP 8.4).

## How it plugs into Grav

- **Sign-in**: `onUserLoginAuthenticate` at priority 1000, the method of the official `login-ldap` plugin: the engine checks the name or email and the password (`POST /sign-ins`); the avatar becomes a Grav user held in the session (`save_accounts` keeps a file; always with the API plugin, which needs one). A refusal lets the accounts of the site try. Its access: `site.login`, `site.w4os.manage` from the level of the setting `admin_level` (one of the engine's `Avatar::LEVELS`, customer service 100 by default): the admin pages open. Each page, action and field then asks the level the contract gives it (`x-access` of its operation, `access.level` of a field of `x-form`; `OpenApi::access()` and `allows()`, which asks the engine's `Api\Access` when the plugin has its library, `Lists::actions()`, `Items::editable()`, `Site::can()`) with the visitor's own level: nothing is written here, and what the visitor may not use is hidden or read only.
- **Forms**: page front matter, processed in `onFormProcessed` (action `w4os`); a refusal goes back to the form with `onFormValidationError`; a success leaves a message and redirects (no form sent twice). The account form is filled in `onFormInitialized`.
- **Pages**: added in `onPagesInitialized` at their route unless the site has a page there (the method of oli-mautic). The forgot-password route is the one of the Login plugin (`/forgot_password`), so its button leads to this page.
- **API key**: typed on the settings page, moved to `user-data://w4os/api.json` by `onAdminSave` (never in the configuration nor in git); the configuration (environment) wins.
- **Localization.** Only what the visitor sees is translated; logs and internal messages stay in English. The engine speaks English with stable codes: the plugin tells each refusal from the `code` of its problem (`Forms::CODES`, the text `w4os.error_<code>`) in its own words, and its English `detail` goes to the log only. The blocks of the helpers come in their own translations (the plugin asks them in the language of the visitor, `Accept-Language`) and are shown as they are: a text the helpers or the engine translate is never translated again here.
- **Language files.** One file per language, Grav's own format (`languages/en.yaml`, `fr`, `de`, `it`, `nl`, `pt-BR`, `cy`), **generated**: `composer languages` (`dev/languages.php`) writes them from the catalog of w4os-engine (`W4OS\Engine\I18n\Catalog`, in `vendor`: the texts every bridge of a grid shares, gettext files in the engine's `share/languages`) and from `i18n/<lang>.yaml`, the texts of Grav alone (its settings page, `admin_*`). A text is changed or translated in the catalog of the engine, or in `i18n/` for the settings page, never in `languages/`; a text missing in a language is the English one. Keys are readable (`w4os.register_title`, `w4os.error_<code>` for each problem code of the engine), values put in with `%s` (`'w4os.manage_enable'|t(name)`, `Site::translate($key, [$name])`). `tests/LanguagesTest.php` checks that the files are up to date and that every problem code has its text; `tests/PagesTest.php` that every key used is in every file.

## Tests

```bash
composer install
dev/grav.sh install         # the Grav whose libraries the tests load (tmp/grav), once
vendor/bin/pest             # GRAV_DIR=<a Grav> vendor/bin/pest: on another Grav, a 1.7 one for instance
```

The plugin runs on Grav 1.7 (PHP 8.2) and Grav 2 (PHP 8.3+), one code base. It never ships a library Grav already has: Twig, Symfony
YAML, the PSR interfaces and the polyfills Grav carries on both lines are `replace`d in `composer.json`, so the plugin uses the
host's, and the tests load them from a Grav (`tests/Pest.php`). A library of the plugin works beside both lines (Twig 1.44 and 3,
Symfony YAML 4.4 and 7); the templates, the plugin's and the helpers', keep to the syntax of Twig 1.44 (no arrow in `sort`, no
`?.`, no `...`, no `types` or `guard` tags). `config.platform.php` (8.2) keeps the locked versions to what PHP 8.2 runs. Where a
feature of Grav 2 alone is needed, the plugin tests for it (`method_exists`, `class_exists`), as Grav's own plugins do.

What needs Grav itself (signing in, "remember me", sessions) is tested end to end by w4os-site (`tests/remember-test.sh`), on the Grav tree it builds with this plugin (`W4OS_PLUGIN=<this folder> packaging/build` there).

## Trying it

On a development machine, never on a live grid: the w4os site (`../w4os-site`) bundles the release zip of this plugin (`dist/`, build it first). Build its package (`apt-package`), install it on the machine of a test grid, `w4os-site setup` with the helpers of that grid, `w4os-site web apache --port <port> --install`. See the DEVELOPERS.md of the w4os site.

The helpers of a branch can be served without being installed: copy the project with its `vendor` (`rsync -azL`, which follows the links of the path repositories), `composer dump-autoload -o` there, make a key of the install, and serve its `public` folder with the web server (`SetEnv OPENSIM_GRID <grid>`, `CGIPassAuth On` for the API key, `FallbackResource /index.php`).

No real mail: test with addresses of `example.org`, and take the tokens from the API with `send: false` (`POST /email-confirmations`, `POST /password-resets`), then open `/register/confirm?token=` or `/reset-password?token=`.

A local Grav, when needed (`dev/grav.sh install`, `dev/grav.sh serve` on http://127.0.0.1:8820, Grav in `tmp/grav`, ignored by git), runs on the PHP built-in server; `dev/grav.sh tunnel` forwards helpers that only listen on a development machine, with `API_TUNNEL` and `API_TUNNEL_HOST` in `.env`.
