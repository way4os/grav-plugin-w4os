# W4OS for Grav

The pages an [OpenSimulator](http://opensimulator.org) grid needs on its website, for any [Grav](https://getgrav.org) site: a plugin that is a thin bridge to the OpenSim engine API. Everything about the avatars is asked of the engine; the site only shows it.

It is one piece of the w4os family:

- **grav-plugin-w4os** (this plugin): reusable on any Grav site. It brings the settings of the engine API, the pages of the accounts and of the managers, and the blocks. No content of its own, no theme imposed.
- **The light w4os site** (a project of its own, with its own apt package and installer): a complete minimal website for a grid, Grav with this plugin, a light theme and the default pages (home, help), set up by its installer.
- **w4os for WordPress**: the same pages for a WordPress site.
- **w4os-app**: the full application, later.

## Features

- **Register**: an avatar chooses its name, its email, its password and its first outfit among the avatar models of the grid. It stays inactive until it clicks the link of the confirmation mail the engine sends (can be switched off). A page of terms to agree to can be required.
- **Sign in** with the name of the avatar (`First Last`) or its email, on the page of the Login plugin, titled "Login" in the language of the page. The accounts of the site (its administrators) keep working. Under its button, "Register – Lost Password" (register while registration is open): `{% include 'w4os/login-links.html.twig' %}` after the form, in a theme's `templates/login.html.twig`, with `route_forgot: false` in the settings of the Login plugin (its own button goes away; this plugin's page answers at `/forgot_password`).
- **Stay signed in** with "remember me", in as many browsers as one likes: a browser back by it is the avatar as the engine has it then (its level, a ban, a deleted avatar), several pages it opens at once keep it signed in, and a stolen cookie signs the avatar out everywhere and lands on the sign-in page, saying why.
- **Forgotten password**: a link by mail to choose a new one.
- **My avatar**: the profile of the avatar, the text of its profile (about, wants, skills, languages, real life, web page), its password, and its email: a new address changes once the link mailed to it is clicked.
- **Its stream**: on its own profile page, signed in on the site, the avatar writes a post at the top of the Feed tab and deletes its own posts (asked to confirm). Its posts show on its profile and in the Feed tab of the viewers; nobody else sees the form.
- **Avatars of the grid**, for its managers (see [Who manages the grid on the site](#who-manages-the-grid-on-the-site)): search, see each one's status (active, disabled, waiting for the confirmation of its email), and from its actions menu enable or disable it, or mail it a password link.
- **Simulators of the grid**, for its managers: their state (enabled, running), and from their actions menu start, stop (asked to confirm) or restart them, as the engine allows the visitor's level (a god).
- **Site settings**, for the managers the engine lets change them (a god): the theme of the site, among the ones installed, saved as Grav's own setting (`system.pages.theme` in `user/config/system.yaml`), the same value Grav's admin writes; the logo and the favicon of the site, set once for every theme (the plugin's setting `brand`, the files in `user/data/theme/images`): the active theme gets them as the upload of its own fields would give them (`custom_logo`, `custom_favicon` or `favicon`, Editorial's `custom_logo_enabled` with them): a copy where the field puts its files, made again after an update of the theme, never over a file of the theme. Whatever the theme the site switches to, it shows them; a theme without such fields gets them in the file it takes them from (Woo's `images/logo.png`, Quark's `images/favicon.png`), its own kept in `user/data/w4os/theme-files` and put back once the site has none; a theme with neither keeps its own (the settings page says so), and without them each theme keeps its own.
- **Blocks of the helpers** in any page, as a shortcode or in a template: grid info, grid status (in the language of the site, numbers and area as the language writes them), message of the day, search, popular places, destination guide, directory, profile.
- **Any theme**: the pages of the plugin show in the default page of the site's theme, whatever it is, which shows their title. The plugin's entries are pages of the menu every theme draws (setting **Entry in the menu**): Search; My avatar, to the profile of the avatar signed in (a visitor goes to the registration), with under it, once signed in, Edit profile and Logout (the Login plugin's own sign-out); Admin for a manager, its sections under it. Signing in and out show as the Login plugin shows them (`partials/login-status.html.twig`, where a theme includes it). "Remember me" is checked on the sign-in page, with the links to register and to a lost password under its form. In a viewer, the pages of the plugin are compact pages of their own, without the theme. A theme may still give its own `w4os-viewer.html.twig` or any template of the plugin.
- **Search** in the top bar: `{% include 'w4os/search-link.html.twig' %}`, to the search page of the site.
- **Admin menu**, for the managers of the grid, before it: the avatars of the grid, and the admin pages other plugins add (event `onW4osAdminMenu`, its `items`: `label` and `url`): `{% include 'w4os/admin-menu.html.twig' %}`.
- In English, French, German, Italian, Dutch, Portuguese (Brazil) and Welsh: the texts come from the translation catalog of the OpenSim engine, shared by every website of a grid (Grav, WordPress); `languages/<lang>.yaml` are generated from it (`composer languages`).

The presentation and help pages are plain Markdown files of the site (`user/pages`), with the blocks in them:

```markdown
[grid-info]
[grid-status]
[web-search]
[avatar-profile name="First.Last"]
[popular-places max=5]
[login-uri]
```

One shortcode by block, named as in w4os-wp; the closing slash is optional (`[grid-info]` or `[grid-info /]`). The page Shortcodes of the admin lists them all with their options.

In a template: `{{ w4os_block('grid-status') }}`.

The home page of a grid, as the content of any page in the site's theme: `[w4os-home]` ... `[/w4os-home]` around its text gives the text, the popular places and the destination guide, with the grid info, status and message of the day and the last news beside them where the content is wide enough (three columns as the splash page of the helpers where it is wider still), under them otherwise. The theme's own column, if it has one, stays the theme's. The blocks come after the text, or where the text has the marker `<!--w4os-blocks-->`.

What only a visitor with no avatar signed in sees (an invitation to register), in a page with `cache_enable: false`:

```markdown
[w4os-guest]Create your avatar, then connect with any OpenSimulator viewer.[/w4os-guest]
```

What shows only while registration is open (setting `register.enabled`), such as a link to the registration page:

```markdown
[w4os-guest][w4os-register][Create your avatar](/register), then connect with any OpenSimulator viewer.[/w4os-register][/w4os-guest]
1. [w4os-register]**Create your avatar** on the [registration page](/register).[/w4os-register]
1. **Install a viewer**.
```

Inside a list item, the shortcode goes after the number: the item left empty is hidden, and the list is numbered without it.

## Themes

The plugin works with any theme: its pages use the theme's default page and its entries are pages of the menu (my avatar, with
signing in and creating an avatar under it for a visitor, editing the profile and signing out once signed in; the admin for a
manager). What a theme does with them is the theme's choice, and its own settings stay the user's: the plugin prints no title of
its own on its pages (the theme shows it or not), and gives the logo and the favicon of the site to the theme in its own fields or
files (see the settings of the site). The account page has its own sign-out link, for the themes that show no entries under my
avatar. Seen in an audit of 15 themes (a visitor, a resident and a god, at 1280 and 393 px):

- **Quark 2** and its children (**Quark 2 Open Publishing**, **Quark**, **Quark-A11y**, **Hadron**) show the content of a page
  alone (no title on the pages of the plugin), Grav's sign-in status beside the menu, and no language switcher; Quark takes its
  favicon from `images/favicon.png` (the site's is put there).
- **Hadron** draws its own language switcher (`partials/langauge-switcher.html.twig`, not the standard
  `partials/langswitcher.html.twig`, so the site's switcher is not involved), labelled from its own language files, which have
  English and German only (`LANGUAGE.ICON` elsewhere); it ships the logos, the favicon and a menu link of its demo site. The plugin
  clears them for each request (its logo fields naming missing files, its `menu`) and sets its switcher to the codes of the
  languages (`langswitcher.format`), unless the site set them itself; its row of languages still takes two lines when someone is
  signed in (its own style).
- **Mundana** dates every page as a post (its date not translated), titles it in its own header above the content, lists every
  language in words in its menu bar and draws large serif headings: a structure to start a theme of one's own from, not a theme to
  use as it is.
- **Editorial**, **Future2021**, **Learn2**, **Apple Notes** and **Hypertext** show the title of a page themselves.
- **Learn2** draws its own logo (no field for one: the settings page says so); its "edit this page" link to the repository of
  its demo is turned off by the plugin (`github.position`), unless the site set it itself.
- **Woo** takes its logo from `images/logo.png` (the site's is put there, its own kept and put back without one) and shows no
  entries under my avatar in its menu: a visitor signs in from the link of the registration page, a resident signs out from the
  account page.
- **Apple Notes** has no menu: its column lists the pages beside the one shown, by their titles, without their children.
- **Hypertext** is plain HTML, no styles of its own; it takes its favicon from the site's images (`user/images/favicon/favicon.png`,
  its option `structure.use_favicon`): the site's is put there and the option turned on, unless the site set it itself.
- **Quark Photofolio** lists the sections of the page shown, not the pages of the site: it has no menu of the site.
- **Saturn**, unmaintained since 2021, has no logo field and its search bar needs a plugin of its own.

### Recommended themes

Free themes that run on Grav 2 and look as they should with the plugin alone, on a new site as on an existing one (the plugin
adapts what it gives a theme, its fields and what it shows, never the theme's style, which is the site's choice): **Quark 2**
(the base of the site's own theme) and **Quark 2 Open Publishing**, **Quark**, **Quark-A11y**, **Future2021**, **Editorial**,
**Learn2** (its menu in a sidebar), **Woo**, **Apple Notes** and **Hypertext** (plain HTML).

Not recommended: **Hadron** and **Mundana** (they work, their own styles get in the way, see above), **Quark Photofolio**, whose
menu lists the children of the page shown (`partials/navigation.html.twig`, `page.children`), not the pages of the site, and
**Saturn** (1.7.1, made for Grav 1.5, unmaintained since 2021, no field for a logo).

## Who manages the grid on the site

The setting **Admin pages from level** (`admin_level`) is the lowest user level of the grid that enters the admin pages of the site: one of OpenSimulator's levels (0 resident, 1 god-like, 100 customer service, 150 liaison, 200 god, 250 maintenance), customer service by default. Inside, each page, action and field asks its own level, the one the engine gives it, and the visitor's own level decides, as in the world: a level has every right of the ones below it, never one above.

So reading the lists and caring for the accounts (an avatar's details, its email, its password, enabling or disabling it) needs customer service (100); changing an avatar's role or level, a region, an instance needs a god (200).

For example, on a grid where Gudule is a god (200) and Suzi a liaison (150), Suzi has nearly all the gods' rights in the world. With the setting at 200, only Gudule manages the grid from the website; Suzi keeps her rights in the world. With it at 150, Suzi also manages the grid from the website, as far as her level 150 allows: she sees the lists and cares for the accounts, but she does not change a role or a region.

The level that decides is the one the engine gives the avatar at sign-in (`accessLevel`): its user level, except for the owner of the grid, the owner of its first estate (the lowest EstateID), who gets at least a god's (200) whatever its user level, as OpenSimulator makes the owner of a region its god (`region_owner_is_god`). So the owner of a grid without gods still manages it from the website. Its user level is not changed, and the lists show it as it is. A later version (opensim-kit ROADMAP, 3.1) gives it only while the grid has no god, through a list of administrators in the settings.

## The previous addresses of the grid

A grid that moves gets new addresses (its welcome page, search, profiles...), while the viewers keep asking the old ones until their user refreshes the grid's info. The engine keeps each old address with its new one (`GET /grids/{grid}/addresses`, `previous`); the plugin adds the ones on the site's own host to Grav's redirects (`site.redirects`, a 301 to the new address, its placeholders such as `[AGENT_NAME]` carried over), so Grav serves them itself. They are read from the engine once an hour, none when it does not answer. Your own list in `user/config/site.yaml` (`redirects:`) comes first and wins on the same address. An old address on another host (an old helpers server, the grid's own) needs a redirect of the web server that answers it.

## Standalone

The plugin ships as a Grav plugin: its release zip (Grav's GPM installs it), and inside the light w4os site; it has no system package of its own. It is self-contained: its release zip (about 3 MB, 10 MB unpacked) carries the OpenSim engine and helpers in its `vendor` folder. A Grav site with this plugin alone works, with no system package and nothing else installed on the server. It reaches the grid in one of two ways (setting **Engine**):

| | **The API of the grid** (`engine: remote`, the default) | **The engine bundled with the plugin** (`engine: bundled`) |
| --- | --- | --- |
| Where | the site can be anywhere | the site reaches the database of the grid's Robust |
| The site needs | the address of the helpers of the grid and an API key | the login address of the grid and its Robust database (or nothing: the OpenSim install of this server) |
| PHP | what Grav needs | also `pdo_mysql` (and `imagick` for the pictures of the models) |
| The grid needs | the OpenSim helpers version 3 (they serve the API and the blocks) | nothing more than OpenSimulator |
| Mails (confirmation, password) | sent by the engine on the machine of the grid | sent by the site (PHP `mail()`) |

### The helpers of the grid

With the bundled engine, the site can also serve the helpers of the grid itself (setting **Serve the helpers**), the ones the plugin carries: what the viewers and the simulators ask (welcome page, search, profiles, destination guide, message of the day, economy, offline messages, grid status), on the address of the site, no other port nor server. They answer only under the prefix of the **Helpers address** of the settings (`/helpers` of `https://grid.example.org/helpers`), and only when the grid sets one of its helpers there (its config, or what its running grid says; the engine's default addresses do not count): set its `WebURL` to the site (`[Const]` of `Robust.ini`, then restart Robust and the simulators). Every other address stays the site's, its pages or its 404: the helpers never take a route that is not theirs. When the web server sends the helpers' addresses elsewhere (helpers installed apart), those requests never reach the site.

Off (the default), the grid keeps the helpers it has, at the **Helpers address** of the settings, and the blocks come from there.

The engine and the helpers read nothing from the server, no folder of config: with the address and key of the engine API, the grid is what the engine of its machine says of it for a site (kept in the site's data, asked again after the cache time); without them, the site describes it from its login address in its own folder. Its databases are reached with the access of the settings (host, port, user, password), each with its own name, wherever they are; what they write is in the site's folders. The pictures need PHP `imagick` with JPEG 2000 (OpenJPEG).

## Requirements

- Grav 1.7.51 or newer (PHP 8.2 or newer) or Grav 2 (PHP 8.3 or newer), with the Form, Login and Shortcode Core plugins (Grav installs them
  with this one).

| Grav | PHP | Supported until |
| --- | --- | --- |
| 2 | 8.3 or newer | as long as Grav 2 |
| 1.7.51 or newer | 8.3 | 31 December 2027 at the latest, PHP 8.3's end of life: Grav 1.7 runs on no newer PHP |
| 1.7.51 or newer | 8.2 (Debian 12) | 31 December 2026, PHP 8.2's end of life; a site can move to PHP 8.3 then |

Grav 1.7 is supported for the servers that have no PHP 8.3 (Debian 12, whose long-term support ends on 30 June 2028). Its support is
reviewed during 2027 by the sites that still use it, and announced deprecated before December 2027 if none does (ROADMAP.md). On Grav
1.7, a recent date in a list reads as a date, not relatively (`il y a 2 heures` on Grav 2).
- An OpenSimulator grid: with its OpenSim helpers (version 3) and an API key for the site, or with its Robust database reachable from the site.

## Install

1. Install the plugin: unzip its release in `user/plugins/w4os` (or `bin/gpm install w4os` once it is published). A copy of the git repository has no `vendor` folder: run `composer install --no-dev` in it, or use the API of the grid.
2. In the admin of the site (Plugins > W4OS), or in `user/config/plugins/w4os.yaml`, choose the engine:
   - **The API of the grid**: on the machine of the grid, make a key for the site (`opensim api-key create my-website`, shown once), then set the **Engine API address** (`https://grid.example.org/api`, or for a site on the machine of the grid the one `opensim web GRID api` serves, on a port of the grid), required, the **API key**, and the **Helpers address** (`https://grid.example.org/helpers`) for the blocks. The settings page keeps the key in `user/data/w4os/api.json`, out of the configuration and of git; without the admin, set it in the environment (`GRAV_CONFIG__PLUGINS__W4OS__API_KEY=osk_...`).
   - **The engine bundled with the plugin**: set the **Login address of the grid** (`http://grid.example.org:8002`) and its Robust **database** (host, port, name, user, password; the password is kept in `user/data/w4os/database.json`). The plugin writes the description of the grid in `user/data/w4os/opensim`. On the machine of the grid, the login address can stay empty: the plugin uses the grid of the OpenSim install of this server (its `w4os.conf`, or the one given).
3. Add your pages (`user/pages`), with the blocks you want. The style sheet of the plugin can be switched off (Built-in CSS) when the theme styles the pages and the blocks.

The pages of the plugin are served at these routes unless the site has its own page there (settings, tab Pages):

| Page | Route |
| --- | --- |
| Create your avatar | `/register` |
| Confirm your email (the link of the mail) | `/register/confirm` |
| Lost password (the link under the sign-in button leads there) | `/forgot_password` |
| New password (the link of the mail) | `/reset-password` |
| My avatar | `/account` |
| The dashboard of the managers (`routes.admin`, never under the route of Grav's admin; the old `/account/...` addresses lead there) | `/w4os` |
| Avatars of the grid (managers) | `/w4os/avatars` |
| Regions of the grid (managers), and the page of each | `/w4os/regions`, `/w4os/regions/region` |
| Simulators of the grid (managers: start, stop, restart, as their level allows) | `/w4os/simulators` |
| Settings of the site (its theme, its logo and favicon; the managers the engine lets change them) | `/w4os/settings` |
| Sign in (Login plugin) | `/login` |
| Pictures of the grid (bundled engine) | `/grid-assets` |

The mails (confirmation, password) are sent by the engine: on the machine of the grid with its API, by the site with the bundled engine. On a test site, **Send the mails** off (`send_mail: false`) mails nothing: the confirmation links of the registrations and of the email changes are shown on the page instead (never the one of a new password).

## License

AGPL-3.0-or-later, like w4os and the OpenSim engine.
