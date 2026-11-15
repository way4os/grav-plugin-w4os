# Developers

## The rule

The engine API provides everything; the plugin only consumes it. An operation that is missing (a check, a field, a mail) goes into the engine (`opensim-engine`, `src/Api/`), never here. The rules of the names, the passwords and the models are the engine's: the plugin checks only what its forms add (the password typed twice, the terms).

w4os (`~/Projects/w4os`, branch `speculoos`) is the reference of the method: the registration form, the models picker, the profile and account pages, the sign-in with the avatar name or the email are all done there. Follow it.

## Layout

- `w4os.php`: the Grav plugin, no logic of its own: the events (sign-in, pages, forms, Twig, shortcode, settings).
- `classes/Engine.php`: the engine API (JSON:API, problems as `EngineException`, Bearer key). `Forms.php`: what the forms ask of the engine, refusals as `FormError` (the key of their text). `Blocks.php`: the blocks of the helpers. Plain PHP, no Grav symbol, tested with a fake transport (`tests/Support.php`).
- `classes/Site.php`: the part that knows Grav: settings and API key, Grav's HTTP client and cache, the pages of the plugin, the avatar as a Grav user.
- `pages/*.md`: the front matter of the pages of the plugin, with their forms (Form plugin, `process: [{w4os: register}]`); title and texts come from `languages.yaml`.
- `templates/w4os.html.twig` and `templates/w4os/<page>.html.twig`: the pages. `templates/forms/fields/w4os_models`: the models picker. A theme overrides any of them.
- `examples/pages`: a home page and a help page, in English and French.

## How it plugs into Grav

- **Sign-in**: `onUserLoginAuthenticate` at priority 1000, the method of the official `login-ldap` plugin: the engine checks the name or email and the password (`POST /sign-ins`); the avatar becomes a Grav user held in the session (`save_accounts` keeps a file; always with the API plugin, which needs one). A refusal lets the accounts of the site try. Its access: `site.login`, and `site.w4os.manage` from the level `manager_level`.
- **Forms**: page front matter, processed in `onFormProcessed` (action `w4os`); a refusal goes back to the form with `onFormValidationError`; a success leaves a message and redirects (no form sent twice). The account form is filled in `onFormInitialized`.
- **Pages**: added in `onPagesInitialized` at their route unless the site has a page there (the method of oli-mautic). The forgot-password route is the one of the Login plugin (`/forgot_password`), so its button leads to this page.
- **API key**: typed on the settings page, moved to `user-data://w4os/api.json` by `onAdminSave` (never in the configuration nor in git); the configuration (environment) wins.
- Every text goes through `languages.yaml` (`PLUGIN_W4OS.*`), English and French at least.

## Tests

```bash
composer install
vendor/bin/pest
```

## Development site

`dev/grav.sh` makes a Grav site in `tmp/grav` (ignored by git) at the versions of the sites (Grav 2.2, Form, Login, Email, Shortcode Core, Quark2), links this plugin into it and copies the example pages. It runs on the PHP built-in server: no container.

```bash
dev/grav.sh install
dev/grav.sh serve       # http://127.0.0.1:8820
```

The site needs the helpers of a grid (version 3, which serve the engine API at `/api` and the blocks at `/blocks`). Never a live grid: a test grid on a development machine. In `tmp/grav/user/config/plugins/w4os.yaml`, `url:` is the address of these helpers; the key goes in `tmp/grav/user/data/w4os/api.json` (`{"api_key": "osk_..."}`).

When the helpers listen only on the development machine, `dev/grav.sh tunnel` forwards them through ssh, with `.env` (not in git):

```bash
API_TUNNEL=8099:127.0.0.1:8099
API_TUNNEL_HOST=dev-machine.example.org
```

To serve the helpers of a branch on the development machine, without installing them: copy the project with its `vendor` (`rsync -azL`, which follows the links of the path repositories), `composer dump-autoload -o` there, make a key of the install, and run them as the web server's user:

```bash
sudo -u www-data env OPENSIM_GRID=<grid> php -S 127.0.0.1:8099 -t public public/index.php
```

No real mail: test with addresses of `example.org`, and take the tokens from the API with `send: false` (`POST /email-confirmations`, `POST /password-resets`), then open `/register/confirm?token=` or `/reset-password?token=`.
