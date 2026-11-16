# W4OS for Grav

The pages an [OpenSimulator](http://opensimulator.org) grid needs on its website, for any [Grav](https://getgrav.org) site: a plugin that is a thin bridge to the OpenSim engine API. Everything about the avatars is asked of the engine; the site only shows it.

It is one piece of the w4os family:

- **grav-plugin-w4os** (this plugin): reusable on any Grav site. It brings the settings of the engine API, the pages of the accounts and of the managers, and the blocks. No content of its own, no theme imposed.
- **The light w4os site** (a project of its own, with its own apt package and installer): a complete minimal website for a grid, Grav with this plugin, a light theme and the default pages (home, help), set up by its installer.
- **w4os for WordPress**: the same pages for a WordPress site.
- **w4os-app**: the full application, later.

## Features

- **Register**: an avatar chooses its name, its email, its password and its first outfit among the avatar models of the grid. It stays inactive until it clicks the link of the confirmation mail the engine sends (can be switched off). A page of terms to agree to can be required.
- **Sign in** with the name of the avatar (`First Last`) or its email, on the page of the Login plugin. The accounts of the site (its administrators) keep working.
- **Forgotten password**: a link by mail to choose a new one.
- **My avatar**: the profile of the avatar, the text of its profile (about, wants, skills, languages, real life, web page), its password, and its email: a new address changes once the link mailed to it is clicked.
- **Avatars of the grid**, for its managers (the avatars of user level 200 or more by default): search, see each one's status (active, disabled, waiting for the confirmation of its email), and from its actions menu enable or disable it, or mail it a password link.
- **Blocks of the helpers** in any page, as a shortcode or in a template: grid info, grid status (in the language of the site, numbers and area as the language writes them), message of the day, search, popular places, destination guide, directory, profile.
- **Menu of the visitor** for the top bar of a theme: the avatar signed in (my avatar, avatars of the grid, sign out), else sign in, create an avatar, forgotten password: `{% include 'w4os/user-menu.html.twig' %}`.
- In English, French, German, Italian, Dutch, Portuguese (Brazil) and Welsh: the texts come from the translation catalog of the OpenSim engine, shared by every website of a grid (Grav, WordPress); `languages/<lang>.yaml` are generated from it (`composer languages`).

The presentation and help pages are plain Markdown files of the site (`user/pages`), with the blocks in them:

```markdown
[w4os block="grid-info" /]
[w4os block="grid-status" /]
[w4os block="search" /]
[w4os block="profile" name="First.Last" /]
```

In a template: `{{ w4os_block('grid-status') }}`.

What only a visitor with no avatar signed in sees (an invitation to register), in a page with `cache_enable: false`:

```markdown
[w4os-guest]Create your avatar, then connect with any OpenSimulator viewer.[/w4os-guest]
```

## Standalone

The plugin is self-contained: its release zip (the one Grav's GPM installs, about 3 MB, 10 MB unpacked) carries the OpenSim engine and helpers in its `vendor` folder. A Grav site with this plugin alone works, with no system package and nothing else installed on the server. It reaches the grid in one of two ways (setting **Engine**):

| | **The API of the grid** (`engine: remote`, the default) | **The engine bundled with the plugin** (`engine: bundled`) |
| --- | --- | --- |
| Where | the site can be anywhere | the site reaches the database of the grid's Robust |
| The site needs | the address of the helpers of the grid and an API key | the login address of the grid and its Robust database (or nothing: the OpenSim install of this server) |
| PHP | what Grav needs | also `pdo_mysql` (and `imagick` for the pictures of the models) |
| The grid needs | the OpenSim helpers version 3 (they serve the API and the blocks) | nothing more than OpenSimulator |
| Mails (confirmation, password) | sent by the engine on the machine of the grid | sent by the site (PHP `mail()`) |

### The helpers of the grid

With the bundled engine, the site can also serve the helpers of the grid itself (setting **Serve the helpers**), the ones the plugin carries: what the viewers and the simulators ask (welcome page, search, profiles, destination guide, message of the day, economy, offline messages, grid status), on the address of the site, no other port nor server. The addresses are the ones the config of the grid gives its services: set its `WebURL` to the site (`[Const]` of `Robust.ini`, then restart Robust and the simulators), and the site answers `/helpers/...`, `/welcome`, `/search`, `/profile/...` as the grid says; every other address stays the site's.

Off (the default), the grid keeps the helpers it has, at the **Helpers address** of the settings, and the blocks come from there.

For now the site serves the helpers of a grid of the OpenSim install of its server (the login address left empty): the helpers read the grid from the install of the server.

## Requirements

- Grav 2 (PHP 8.3 or newer), with the Form, Login and Shortcode Core plugins (Grav installs them with this one).
- An OpenSimulator grid: with its OpenSim helpers (version 3) and an API key for the site, or with its Robust database reachable from the site.

## Install

1. Install the plugin: unzip its release in `user/plugins/w4os` (or `bin/gpm install w4os` once it is published). A copy of the git repository has no `vendor` folder: run `composer install --no-dev` in it, or use the API of the grid.
2. In the admin of the site (Plugins > W4OS), or in `user/config/plugins/w4os.yaml`, choose the engine:
   - **The API of the grid**: on the machine of the grid, make a key for the site (`opensim api-key create my-website`, shown once), then set the **Helpers address** (`https://grid.example.org/helpers`) and the **API key**. The settings page keeps the key in `user/data/w4os/api.json`, out of the configuration and of git; without the admin, set it in the environment (`GRAV_CONFIG__PLUGINS__W4OS__API_KEY=osk_...`).
   - **The engine bundled with the plugin**: set the **Login address of the grid** (`http://grid.example.org:8002`) and its Robust **database** (host, port, name, user, password; the password is kept in `user/data/w4os/database.json`). The plugin writes the description of the grid in `user/data/w4os/opensim`. On the machine of the grid, the login address can stay empty: the plugin uses the grid of the OpenSim install of this server (its `opensim.conf`, or the one given).
3. Add your pages (`user/pages`), with the blocks you want. The style sheet of the plugin can be switched off (Built-in CSS) when the theme styles the pages and the blocks.

The pages of the plugin are served at these routes unless the site has its own page there (settings, tab Pages):

| Page | Route |
| --- | --- |
| Create your avatar | `/register` |
| Confirm your email (the link of the mail) | `/register/confirm` |
| Forgotten password (the button of the sign-in page leads there) | `/forgot_password` |
| New password (the link of the mail) | `/reset-password` |
| My avatar | `/account` |
| Avatars of the grid (managers) | `/account/avatars` |
| Sign in (Login plugin) | `/login` |
| Pictures of the grid (bundled engine) | `/grid-assets` |

The mails (confirmation, password) are sent by the engine: on the machine of the grid with its API, by the site with the bundled engine. On a test site, **Send the mails** off (`send_mail: false`) mails nothing: the confirmation links of the registrations and of the email changes are shown on the page instead (never the one of a new password).

## License

AGPL-3.0-or-later, like w4os and the OpenSim engine.
