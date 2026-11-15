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
- **Avatars of the grid**, for its managers (the avatars of user level 200 or more by default): search, enable, disable, mail a password link, delete.
- **Blocks of the helpers** in any page, as a shortcode or in a template: grid info, grid status, message of the day, search, popular places, destination guide, directory, profile.
- English and French.

The presentation and help pages are plain Markdown files of the site (`user/pages`), with the blocks in them:

```markdown
[w4os block="grid-info" /]
[w4os block="grid-status" /]
[w4os block="search" /]
[w4os block="profile" name="First.Last" /]
```

In a template: `{{ w4os_block('grid-status') }}`.

## Requirements

- Grav 2 (PHP 8.3 or newer), with the Form, Login and Shortcode Core plugins (Grav installs them with this one).
- An OpenSimulator grid with the OpenSim helpers (`opensim-helpers`, version 3), which serve the engine API and the blocks, and an API key for the site.

## Install

1. Install the plugin: copy this folder to `user/plugins/w4os` (or `bin/gpm install w4os` once it is published).
2. On the machine of the grid, make a key for the site: `opensim api-key create my-website`. It is shown once.
3. In the admin of the site (Plugins > W4OS), or in `user/config/plugins/w4os.yaml`:
   - **Helpers address**: the address of the helpers of the grid, `https://grid.example.org/helpers`;
   - **API key**: the key of step 2. The settings page keeps it in `user/data/w4os/api.json`, out of the configuration and of git. Without the admin, set it in the environment: `GRAV_CONFIG__PLUGINS__W4OS__API_KEY=osk_...`.
4. Add your pages (`user/pages`), with the blocks you want. The style sheet of the plugin can be switched off (Built-in CSS) when the theme styles the pages and the blocks.

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

The mails (confirmation, password) are sent by the engine, on the machine of the grid. On a test site, **Send the mails** off (`send_mail: false`) mails nothing: the confirmation links of the registrations and of the email changes are shown on the page instead (never the one of a new password).

## License

AGPL-3.0-or-later, like w4os and the OpenSim engine.
