# Developers

## The rule

The engine API provides everything; the plugin only consumes it. An operation that is missing (a check, a field, a mail) goes into the engine (`opensim-engine`, `src/Api/`), never here. The rules of the names, the passwords and the models are the engine's: the plugin checks only what its forms add (the password typed twice, the terms).

w4os (`~/Projects/w4os`, branch `speculoos`) is the reference of the method: the registration form, the models picker, the profile and account pages, the sign-in with the avatar name or the email are all done there. Follow it.

## Two projects

The plugin and the site are two projects:

- **This plugin** is reusable on any Grav site: settings, the pages of the accounts and of the managers, the blocks. It holds no content of the site and imposes no theme (its style sheet can be switched off).
- **The light w4os site** is a project of its own, with its own apt package and installer: Grav, this plugin as a dependency, a light theme, the default `.md` pages, and the installer that sets the address and the key of the API. What belongs to a site (pages, theme, Grav config) goes there.

## Layout

- `w4os.php`: the Grav plugin, no logic of its own: the events (sign-in, pages, forms, Twig, shortcode, settings).
- `classes/Engine.php`: the engine API (JSON:API, problems as `EngineException`, Bearer key). `Forms.php`: what the forms ask of the engine, refusals as `FormError` (the key of their text). `Blocks.php`: the blocks of the helpers. Plain PHP, no Grav symbol, tested with a fake transport (`tests/Support.php`).
- `classes/Site.php`: the part that knows Grav: settings and API key, Grav's HTTP client and cache, the pages of the plugin, the avatar as a Grav user.
- `pages/*.md`: the front matter of the pages of the plugin, with their forms (Form plugin, `process: [{w4os: register}]`); title and texts come from `languages.yaml`.
- `templates/w4os.html.twig` and `templates/w4os/<page>.html.twig`: the pages; `templates/w4os/user-menu.html.twig`: the menu of the visitor, for a theme; `templates/w4os/blocks/grid-status.html.twig`: the status of the grid from the data of its block (`?format=json`), in the language of the site (`classes/Format.php`, intl). `templates/forms/fields/w4os_models`: the models picker. A theme overrides any of them.
- `dev/`: a local development Grav (`dev/grav.sh`, its config in `dev/site`); the pages of a site belong to the site (the w4os site has the default ones), not to the plugin.

## How it plugs into Grav

- **Sign-in**: `onUserLoginAuthenticate` at priority 1000, the method of the official `login-ldap` plugin: the engine checks the name or email and the password (`POST /sign-ins`); the avatar becomes a Grav user held in the session (`save_accounts` keeps a file; always with the API plugin, which needs one). A refusal lets the accounts of the site try. Its access: `site.login`, and `site.w4os.manage` from the level `manager_level`.
- **Forms**: page front matter, processed in `onFormProcessed` (action `w4os`); a refusal goes back to the form with `onFormValidationError`; a success leaves a message and redirects (no form sent twice). The account form is filled in `onFormInitialized`.
- **Pages**: added in `onPagesInitialized` at their route unless the site has a page there (the method of oli-mautic). The forgot-password route is the one of the Login plugin (`/forgot_password`), so its button leads to this page.
- **API key**: typed on the settings page, moved to `user-data://w4os/api.json` by `onAdminSave` (never in the configuration nor in git); the configuration (environment) wins.
- Every text goes through the language files, one per language (`languages/en.yaml`, `languages/fr.yaml`...: Grav's own system, not gettext), with readable keys (`w4os.register_title`); `tests/PagesTest.php` checks that every key used is in every file. Grav looks a key up as a path, any case. A refusal of the engine is told from the `code` of its problem (`Forms::ERRORS`), never from its English `detail`, which is shown only for the generic `invalid-request`.

## Tests

```bash
composer install
vendor/bin/pest
```

## Trying it

On a development machine, never on a live grid: the w4os site (`../w4os-site`) bundles this plugin at its `HEAD`. Build its package there (`apt-package`), install it on the machine of a test grid, `w4os-site setup` with the helpers of that grid, `w4os-site web apache --port <port> --install`. See the DEVELOPERS.md of the w4os site.

The helpers of a branch can be served without being installed: copy the project with its `vendor` (`rsync -azL`, which follows the links of the path repositories), `composer dump-autoload -o` there, make a key of the install, and serve its `public` folder with the web server (`SetEnv OPENSIM_GRID <grid>`, `CGIPassAuth On` for the API key, `FallbackResource /index.php`).

No real mail: test with addresses of `example.org`, and take the tokens from the API with `send: false` (`POST /email-confirmations`, `POST /password-resets`), then open `/register/confirm?token=` or `/reset-password?token=`.

A local Grav, when needed (`dev/grav.sh install`, `dev/grav.sh serve` on http://127.0.0.1:8820, Grav in `tmp/grav`, ignored by git), runs on the PHP built-in server; `dev/grav.sh tunnel` forwards helpers that only listen on a development machine, with `API_TUNNEL` and `API_TUNNEL_HOST` in `.env`.
